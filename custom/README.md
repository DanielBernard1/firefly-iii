# Custom Firefly III image with an "Investments" page

This folder contains everything needed to run **your own Firefly III image**: the official
image (`fireflyiii/core:version-6.7.8`) plus the files of the "Investments" page.

- `Dockerfile` – the recipe for the image. It starts from the official image and copies only the new files.
- `Dockerfile.dockerignore` – tells Docker which files of this repository it needs (only the new ones).
- `apply-changes.sh` – runs while the image is built and makes the two one-line edits to existing
  Firefly III files (the route and the menu entry). It stops the build with an error if it can't.

The image also adds two database tables (products and prices). Firefly III creates them by itself
when the container starts, like during any update. **Make a backup of your database first.**

## How it works

| What | Where it lives | Effect on your normal finances |
|---|---|---|
| Money you move to a broker, buys and sells | Normal Firefly III **transfers** between your own accounts | None. A transfer between your own accounts is neither income nor an expense. |
| Your products (name, ISIN, shares, amount paid) | Table `investment_products`, edited on the Investments page | None |
| Price history | Table `investment_prices`, filled by the price update | None |
| Changes in market value | Automatic **transfers** between the depot and the account **"Investment value changes"** | None for income, expenses and budgets. Net worth includes the market value of your depots. |

"Investment value changes" is an asset account that the price update creates by itself. It has
**"Include in net worth" switched off** and collects the opposite side of every value change (its
balance is minus your total gains). Don't use it for anything else. The transfers it makes have the
tag `investment-value-change` and list shares and prices in their notes.

## Setting up your accounts

Example with a Scalable depot, the Scalable cash balance and a Sparkasse depot:

| Account (asset account) | Notes | Products on the Investments page |
|---|---|---|
| Scalable Balance | `investment: true` and `broker: Scalable Capital` | none: shown as cash |
| Scalable Depot | `investment: true` and `broker: Scalable Capital` | e.g. MSCI World, MSCI EM, Apple |
| Sparkasse Depot | `investment: true` and `broker: Sparkasse` | e.g. a Deka fund |

1. Create the three asset accounts (role "Savings account" is a good choice) and put the lines above
   in their **notes**. Only `investment: true` is required. Upper/lower case, extra spaces, `=`
   instead of `:`, Markdown `- ` and `**bold**` are all fine.
2. Enter what you have paid in so far as **transfers**, for example Girokonto → Scalable Balance and
   Scalable Balance → Scalable Depot.
3. On the **Investments** page, click **Add product** for every product in a depot: name, ISIN,
   number of shares and the amount you paid for them. The Yahoo Finance symbol is optional: when
   it is empty, it is looked up from the ISIN (see "How the symbol is found" below).
4. Click **Update prices now**. Each depot's balance then becomes its market value.
5. Set up the automatic update (below).

### When you buy or sell

1. Enter the money as a **transfer** (buy: Scalable Balance → Scalable Depot; sell: the other way).
2. On the Investments page, click the pencil next to the product and change **shares** and
   **amount paid**. A savings plan means doing this now and then; the numbers only need to be right
   at the next price update.

If you forget step 2, the next update books the difference as a value change (for example a buy
of €100 as a €100 loss). Fix the shares and run the update again: it corrects itself, because each
update compares the market value with the depot's balance and only books the difference.

### How the symbol is found

The same fund is listed on many exchanges, in different currencies (for example IE00B4L5Y983 is
EUNL.DE on XETRA in euro, but IWDA.L in London in US dollars). The lookup:

1. Searches Yahoo for the ISIN and keeps only listings on euro exchanges, in this order:
   XETRA (`.DE`), Frankfurt (`.F`), the other German exchanges, Amsterdam (`.AS`), Paris (`.PA`),
   Milan (`.MI`). London, Swiss and US listings are skipped.
2. If Yahoo's ISIN search only returns non-euro listings (which happens), it searches again by the
   fund's name and keeps only results with exactly the same name.
3. Checks the best candidates one by one and takes the first whose price really is in euro.
4. If there is none, the update says so for that product. Enter a symbol yourself on the product
   (for example `EUNL.DE`), or enter the price by hand.

A product that already has a symbol in the wrong currency (for example `IWDA.L`) is fixed by the
next update: it looks up a euro listing for the ISIN and saves that symbol instead.

To see which symbol an ISIN gets, without saving anything:

```bash
docker compose exec -T app php artisan firefly-iii:investments-update --lookup=IE00B4L5Y983
```

### Products without a Yahoo price

Some funds (often at the Sparkasse) are not on Yahoo Finance. Open the product with the pencil and
fill in **Price today**. The next update uses the latest price you entered.

### What the page shows

- **Overview:** every investment account with its balance, share of the total and 30-day change.
- **One table per depot:** shares, latest price, value, amount paid, gain or loss, change since the
  previous price and since 30 days ago, with totals.
- **Value per product:** a chart per depot, for the date range at the top of the page, and next to
  it a pie chart with the share of each product in that depot.
- The balance chart and three allocation pie charts: by account, by broker and **by product** (all
  depots together). Products without a price are left out of the product charts; a note below the
  chart names them.

## Automatic price update (every 3 days)

The update is one command inside the Firefly III container:

```bash
docker compose exec -T app php artisan firefly-iii:investments-update
```

(`app` is the name of the Firefly III service in your `docker-compose.yml`; use yours.) Run it once
by hand to see the result. To run it every 3 days at 07:15, open the crontab of the user that runs
Docker on your server with `crontab -e` and add this line (use the folder of your
`docker-compose.yml`):

```
15 7 */3 * * cd /path/to/your/firefly && docker compose exec -T app php artisan firefly-iii:investments-update >> /var/log/firefly-investments.log 2>&1
```

`*/3` means the 1st, 4th, 7th, ... of each month, so roughly every 3 days. Each run adds at most
one transfer per depot (none when the value did not change). `--no-prices` only books value changes
without getting new prices.

Yahoo Finance has no official API: the update uses the same addresses as its website, without an
account or key. If Yahoo changes them, the update reports an error per product and books nothing
for that depot; enter prices by hand until it is fixed.

## Build the image on your Ubuntu server

You need Docker and git on the server (you already have Docker).

```bash
# 1. Get the code (only the first time). Use your fork's URL and the branch with these changes.
git clone https://github.com/DanielBernard1/firefly-iii.git ~/firefly-iii-custom
cd ~/firefly-iii-custom
git checkout main            # or the branch you merged the changes into

# Later, to get updates of your own code:
#   cd ~/firefly-iii-custom && git pull

# 2. Build the image. Run this in the root of the repository (note the "." at the end).
docker build -f custom/Dockerfile -t firefly-iii-custom:6.7.8 .
```

This downloads the official image `fireflyiii/core:version-6.7.8` once, adds the investments files,
and saves the result on your server as `firefly-iii-custom:6.7.8`. Nothing is uploaded anywhere.
It takes less than a minute. If you see `[investments] All changes applied.` it worked.

## Switch your docker-compose.yml to the new image

**First make a backup of your database** (as you would before any Firefly III update).
The custom image adds two tables to the database, and the price update books transfers, so make
sure you have a backup you can go back to.

Open your `docker-compose.yml` and find the Firefly III app service. Change only the `image:` line:

```yaml
services:
  app:
    # image: fireflyiii/core:version-6.7.8     # <- the official image (keep this line as a comment)
    image: firefly-iii-custom:6.7.8             # <- your own image
    # ... everything else stays exactly the same (volumes, env_file, ports, etc.)
```

Then, in the folder with your `docker-compose.yml`:

```bash
docker compose up -d        # recreates only the app container with the new image
docker compose logs -f app  # optional: watch it start, press Ctrl+C to stop watching
```

Your data is in the database and in the volumes, not in the image, so nothing is lost.

> If your compose file has `pull_policy: always`, remove it or set it to `missing` for this service,
> otherwise Docker tries to download `firefly-iii-custom` from the internet and fails.
> Running `docker compose pull` will also print a warning for this image; that is harmless.

## Roll back to the official image

1. In `docker-compose.yml`, put the official image back:
   ```yaml
   image: fireflyiii/core:version-6.7.8
   ```
2. Run `docker compose up -d`, and remove the cron line.

Firefly III keeps working with your data as before:
- The transfers that were already booked (including the value changes) stay; they are normal
  transfers. The depots keep their last market value.
- The account "Investment value changes" stays. You can delete it; that also deletes its transfers,
  so the depots go back to the amounts you paid in.
- The two tables `investment_products` and `investment_prices` stay in the database. The official
  image does not use them. They come back to life if you switch to the custom image again.

## Moving to a newer Firefly III version later

The image is built *on top of* an official Firefly III image. The investments page itself lives in
new files only, and the two small edits are made by `apply-changes.sh` on whatever version you build on.
So in most cases upgrading is just building again with a newer base image:

1. Read the Firefly III release notes for the new version (and back up your database).
2. Check the exact tag exists on Docker Hub: <https://hub.docker.com/r/fireflyiii/core/tags>
   (tags look like `version-6.7.9`).
3. Build with the new version:
   ```bash
   cd ~/firefly-iii-custom
   docker build -f custom/Dockerfile --build-arg FIREFLY_VERSION=6.7.9 -t firefly-iii-custom:6.7.9 .
   ```
   (Or change `ARG FIREFLY_VERSION=6.7.8` in `custom/Dockerfile` so you don't need `--build-arg`.)
4. Change `image:` in `docker-compose.yml` to `firefly-iii-custom:6.7.9` and run `docker compose up -d`.
5. Open the Investments page and check that it works. If something is wrong, go back to the previous
   image tag (`firefly-iii-custom:6.7.8` is still on your server) and run `docker compose up -d`.
   Note: going back to an older version is only safe if the newer version did not upgrade your
   database. Firefly III usually does this on start, which is why step 1 says: make a backup.

What can go wrong:

- **The build stops with "could not find the place for the menu entry"**: Firefly III changed its menu
  file. Open `resources/views/components/layout/sidebar.blade.php` of the new version, find a line
  that is still there (for example another heading), and update `ANCHOR` in `custom/apply-changes.sh`.
- **The page shows an error after upgrading**: Firefly III changed something the page uses
  (for example how balances are calculated). The code to look at is in `app/Support/Investments/`
  and `app/Http/Controllers/Investments/`. Stay on the previous image until it is fixed.

### Optional: also merge the newer Firefly III code into your fork

You only need this if you want to work on the source code against the new version (for example to run
the tests). Building the image does **not** need it, because the image takes all Firefly III code from
the official image. To merge:

```bash
git remote add upstream https://github.com/firefly-iii/firefly-iii.git   # only once
git fetch upstream --tags
git merge v6.7.9          # the release tag you want
```

Because almost all custom code is in new files, conflicts are unlikely. Only two existing files
were changed, each by a single line:

- `routes/web.php` – the last line, `require __DIR__.'/custom/investments.php';`
- `resources/views/components/layout/sidebar.blade.php` – the line `@include('investments.menu-item')`

If git reports a conflict in one of them, keep the new upstream version of the file and add the
single line again.

## Check what is in your image

```bash
docker run --rm --entrypoint sh firefly-iii-custom:6.7.8 -c 'tail -n 2 routes/web.php; grep -n investments resources/views/components/layout/sidebar.blade.php'
```

## Notes

- The default base image is `fireflyiii/core:version-6.7.8`, the same version as this repository's
  source code. Its digest on 2026-10-11 was
  `sha256:db249101cac3047b5973946284ad594c698945b8253f0581c5edaccf6b7903d5`; you can see the digest
  of the image on your server with `docker image inspect fireflyiii/core:version-6.7.8 --format '{{json .RepoDigests}}'`.
- The image takes all Firefly III code from the official image and only adds the investments files.
  It can still be built on 6.7.7 with `--build-arg FIREFLY_VERSION=6.7.7`; the two files that
  `apply-changes.sh` edits are the same in 6.7.7 and 6.7.8.
- Firefly III's `.gitignore` ignores everything in `resources/lang/` (translations are downloaded
  separately). The file `resources/lang/en_US/investments.php` was therefore added with `git add -f`.
  Do the same if you add a translation, for example `git add -f resources/lang/de_DE/investments.php`,
  and add a matching `COPY` line to `custom/Dockerfile` and a `!resources/lang/de_DE/investments.php`
  line to `custom/Dockerfile.dockerignore`.
