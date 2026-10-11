# Custom Firefly III image with an "Investments" page

This folder contains everything needed to run **your own Firefly III image**: the official
image (`fireflyiii/core:version-6.7.7`) plus the files of the "Investments" page.

- `Dockerfile` – the recipe for the image. It starts from the official image and copies only the new files.
- `Dockerfile.dockerignore` – tells Docker which files of this repository it needs (only the new ones).
- `apply-changes.sh` – runs while the image is built and makes the two one-line edits to existing
  Firefly III files (the route and the menu entry). It stops the build with an error if it can't.

## How to use the page

Edit an asset account in Firefly III and put these lines in its **notes**:

```
investment: true
broker: Trade Republic
isin: IE00B4L5Y983
shares: 12.5
```

Only `investment: true` is required. Upper/lower case, extra spaces, `=` instead of `:`, Markdown
list markers (`- `) and bold (`**investment**: true`) are all fine. `yes`, `y`, `1` and `on` work as
well as `true`. Shares can be written as `12.5`, `12,5`, `1,234.5` or `1.234,5` (a single `,` or `.`
is always read as the decimal separator). Other text in the notes is ignored. Inactive accounts are
not shown.

Then click **Investments** in the menu on the left (under "Financial control").
The balance chart uses the date range at the top of the page, like other Firefly III pages.

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
docker build -f custom/Dockerfile -t firefly-iii-custom:6.7.7 .
```

This downloads the official image `fireflyiii/core:version-6.7.7` once, adds the investments files,
and saves the result on your server as `firefly-iii-custom:6.7.7`. Nothing is uploaded anywhere.
It takes less than a minute. If you see `[investments] All changes applied.` it worked.

## Switch your docker-compose.yml to the new image

**First make a backup of your database** (as you would before any Firefly III update).
The investments page only reads data, but a backup is always a good idea.

Open your `docker-compose.yml` and find the Firefly III app service. Change only the `image:` line:

```yaml
services:
  app:
    # image: fireflyiii/core:version-6.7.7     # <- the official image (keep this line as a comment)
    image: firefly-iii-custom:6.7.7             # <- your own image
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
   image: fireflyiii/core:version-6.7.7
   ```
2. Run `docker compose up -d`.

That's all. The investments page only reads data and does not change the database, so the
official image works with your data exactly as before. The notes lines (`investment: true` etc.)
simply stay in your account notes as normal text.

## Moving to a newer Firefly III version later

The image is built *on top of* an official Firefly III image. The investments page itself lives in
new files only, and the two small edits are made by `apply-changes.sh` on whatever version you build on.
So in most cases upgrading is just building again with a newer base image:

1. Read the Firefly III release notes for the new version (and back up your database).
2. Check the exact tag exists on Docker Hub: <https://hub.docker.com/r/fireflyiii/core/tags>
   (tags look like `version-6.7.8`).
3. Build with the new version:
   ```bash
   cd ~/firefly-iii-custom
   docker build -f custom/Dockerfile --build-arg FIREFLY_VERSION=6.7.8 -t firefly-iii-custom:6.7.8 .
   ```
   (Or change `ARG FIREFLY_VERSION=6.7.7` in `custom/Dockerfile` so you don't need `--build-arg`.)
4. Change `image:` in `docker-compose.yml` to `firefly-iii-custom:6.7.8` and run `docker compose up -d`.
5. Open the Investments page and check that it works. If something is wrong, go back to the previous
   image tag (`firefly-iii-custom:6.7.7` is still on your server) and run `docker compose up -d`.
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
git merge v6.7.8          # the release tag you want
```

Because almost all custom code is in new files, conflicts are unlikely. Only two existing files
were changed, each by a single line:

- `routes/web.php` – the last line, `require __DIR__.'/custom/investments.php';`
- `resources/views/components/layout/sidebar.blade.php` – the line `@include('investments.menu-item')`

If git reports a conflict in one of them, keep the new upstream version of the file and add the
single line again.

## Check what is in your image

```bash
docker run --rm --entrypoint sh firefly-iii-custom:6.7.7 -c 'tail -n 2 routes/web.php; grep -n investments resources/views/components/layout/sidebar.blade.php'
```

## Notes

- The official image tag `version-6.7.7` was checked on Docker Hub on 2026-10-11. Its digest was
  `sha256:2f5e2595bc1ac06276032d6fc89febb4ecfd3f73fa0630e7a97693bb98bde431`; you can see the digest
  of the image on your server with `docker image inspect fireflyiii/core:version-6.7.7 --format '{{json .RepoDigests}}'`.
- This repository's own source code is at Firefly III 6.7.8. That does not matter for the image:
  the image takes all Firefly III code from the official 6.7.7 image and only adds the investments files.
- Firefly III's `.gitignore` ignores everything in `resources/lang/` (translations are downloaded
  separately). The file `resources/lang/en_US/investments.php` was therefore added with `git add -f`.
  Do the same if you add a translation, for example `git add -f resources/lang/de_DE/investments.php`.
