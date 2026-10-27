// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

(function() {
    'use strict';

    const normalize = function(value) {
        return value.trim().toLocaleLowerCase();
    };

    const initializeTableFilters = function(tools) {
        const search = tools.querySelector('[data-table-search]');
        const filters = Array.from(tools.querySelectorAll('[data-filter-field]'));
        const status = tools.querySelector('[data-filter-status]');
        const reset = tools.querySelector('[data-table-reset]');

        if (!search || !status || !reset) {
            return;
        }

        const table = document.getElementById(search.getAttribute('aria-controls'));
        if (!table) {
            return;
        }

        const update = function() {
            const query = normalize(search.value);
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            let visible = 0;

            rows.forEach(function(row) {
                const matchesSearch = normalize(row.textContent || '').includes(query);
                const matchesFilters = filters.every(function(filter) {
                    if (!filter.value) {
                        return true;
                    }
                    return row.getAttribute('data-' + filter.dataset.filterField) === filter.value;
                });
                const matches = matchesSearch && matchesFilters;
                row.hidden = !matches;
                if (matches) {
                    visible += 1;
                }
            });

            status.textContent = visible === 0 && rows.length > 0
                ? status.dataset.noResults
                : status.dataset.resultsTemplate
                    .replace('{$a->visible}', String(visible))
                    .replace('{$a->total}', String(rows.length));
        };

        search.addEventListener('input', update);
        filters.forEach(function(filter) {
            filter.addEventListener('change', update);
        });
        reset.addEventListener('click', function() {
            search.value = '';
            filters.forEach(function(filter) {
                filter.value = '';
            });
            update();
        });

        update();
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.analytics-dashboard__table-tools').forEach(initializeTableFilters);
    });
})();
