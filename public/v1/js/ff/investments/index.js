/*
 * index.js
 *
 * Custom addition (not part of upstream Firefly III): charts on the "Investments" page.
 * Uses the chart helpers from v1/js/ff/charts.js, like other Firefly III pages do.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

$(function () {
    "use strict";

    // same options as the other charts, but with a legend so you can tell the accounts apart.
    var lineOptions = $.extend(true, {}, defaultChartOptions, {legend: {display: true}});
    lineOptions.scales.yAxes[0].ticks.beginAtZero = false;
    var pieOptions = $.extend(true, {}, pieOptionsWithCurrency, {legend: {display: true, position: 'bottom'}});

    // one line per investment account, for the date range selected at the top of the page.
    drawAChart(investmentsBalanceChartUrl, 'investments-balance-chart', 'line', lineOptions, true);

    // current allocation, by account and by broker.
    drawAChart(investmentsAllocationAccountUrl, 'investments-allocation-account', 'pie', pieOptions, false);
    drawAChart(investmentsAllocationBrokerUrl, 'investments-allocation-broker', 'pie', $.extend(true, {}, pieOptions), false);
    // current value per product: over all depots, and per depot (only drawn when there are products).
    drawAChart(investmentsAllocationProductUrl, 'investments-allocation-product', 'pie', $.extend(true, {}, pieOptions), false);
    $('canvas.investments-depot-allocation').each(function () {
        drawAChart($(this).data('url'), this.id, 'pie', $.extend(true, {}, pieOptions), false);
    });

    // value of each product, one chart per depot. Lines connect across the days without a price.
    // each chart gets a fixed height instead of following the width.
    $('canvas.investments-products-chart').each(function () {
        var options = $.extend(true, {}, lineOptions, {spanGaps: true, maintainAspectRatio: false});
        this.parentNode.style.position = 'relative';
        this.parentNode.style.height = '300px';
        drawAChart($(this).data('url'), this.id, 'line', options, true);
    });
});
