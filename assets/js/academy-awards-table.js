/**
 * Academy Awards Table - Frontend JavaScript
 * DataTables (server-side) + filtering + IMDb links + mobile-first UX
 */

(function($) {
    'use strict';

    // Main Application Object
    const AcademyAwardsTable = {

        // Configuration
        config: {
            tableSelector: '#aat-awards-table',
            containerSelector: '.aat-container',
            filtersSelector: '.aat-filters',
            dataTable: null,
            metaLoaded: false,
            cachedMeta: null,
            metaTotals: {
                categories: 0,
                ceremonies: 0
            },
            currentFilters: {
                category: '',
                class: '',
                year: '',
                ceremony: '',
                winnersOnly: false,
                decadePrefix: '' // used by decade quick filters (column search on Year)
            }
        },

        // Initialize
        init: function() {
            this.loadInitialStateFromDOM();
            this.bindEvents();
            this.initQuickFilters();
            this.initDataTableServerSide();
            this.loadMeta();
        },

        // Read initial filter state from shortcode data-* attributes
        loadInitialStateFromDOM: function() {
            const $c = $(this.config.containerSelector).first();
            if (!$c.length) return;

            const initialCategory = $c.data('initial-category');
            const initialClass = $c.data('initial-class');
            const initialYear = $c.data('initial-year');
            const initialCeremony = $c.data('initial-ceremony');
            const initialWinnersOnly = $c.data('initial-winners-only');

            if (initialCategory) this.config.currentFilters.category = initialCategory;
            if (initialClass) this.config.currentFilters.class = initialClass;
            if (initialYear) this.config.currentFilters.year = initialYear;
            if (initialCeremony) this.config.currentFilters.ceremony = initialCeremony;

            this.config.currentFilters.winnersOnly = (
                initialWinnersOnly === true ||
                initialWinnersOnly === 1 ||
                initialWinnersOnly === '1' ||
                initialWinnersOnly === 'true'
            );

            $('#aat-filter-winners').prop('checked', this.config.currentFilters.winnersOnly);
        },

        // Bind Events
        bindEvents: function() {
            const self = this;

            // Filter changes
            $(document).on('change', '#aat-filter-category', function() {
                self.config.currentFilters.category = $(this).val();
                self.applyFilters();
            });

            $(document).on('change', '#aat-filter-class', function() {
                self.config.currentFilters.class = $(this).val();
                self.applyFilters();
            });

            $(document).on('change', '#aat-filter-year', function() {
                self.config.currentFilters.year = $(this).val();
                // exact year overrides any decade prefix
                self.config.currentFilters.decadePrefix = '';
                self.applyFilters();
            });

            $(document).on('change', '#aat-filter-ceremony', function() {
                self.config.currentFilters.ceremony = $(this).val();
                self.applyFilters();
            });

            $(document).on('change', '#aat-filter-winners', function() {
                self.config.currentFilters.winnersOnly = $(this).is(':checked');
                self.applyFilters();
            });

            // Reset filters
            $(document).on('click', '.aat-btn-reset', function() {
                self.resetFilters();
            });

            // Quick filter clicks
            $(document).on('click', '.aat-quick-filter', function() {
                const filter = $(this).data('filter');
                const value = $(this).data('value');

                $('.aat-quick-filter').removeClass('active');
                $(this).addClass('active');

                if (filter === 'category') {
                    self.config.currentFilters.category = value;
                    $('#aat-filter-category').val(value);
                    self.applyFilters();
                    return;
                }

                if (filter === 'class') {
                    self.config.currentFilters.class = value;
                    $('#aat-filter-class').val(value);
                    self.applyFilters();
                    return;
                }

                if (filter === 'decade') {
                    self.filterByDecade(value);
                }
            });
        },

        // Load filter dropdown values + global counts (one-time, cached)
        loadMeta: function() {
            const self = this;

            // Return cached meta if already loaded to avoid redundant AJAX calls
            if (self.config.cachedMeta) {
                self.populateFilters(self.config.cachedMeta);
                self.updateMetaStats(self.config.cachedMeta);
                return;
            }

            $.ajax({
                url: aatData.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'aat_get_awards_meta',
                    nonce: aatData.nonce
                },
                success: function(response) {
                    if (response && response.success) {
                        self.config.metaLoaded = true;
                        self.config.cachedMeta = response.data;
                        self.populateFilters(response.data);
                        self.updateMetaStats(response.data);
                    }
                }
            });
        },

        // Populate Filter Dropdowns (batch DOM insertion via HTML strings)
        populateFilters: function(data) {
            const self = this;
            const $categorySelect = $('#aat-filter-category');
            const $classSelect = $('#aat-filter-class');
            const $yearSelect = $('#aat-filter-year');
            const $ceremonySelect = $('#aat-filter-ceremony');

            if ($categorySelect.find('option').length <= 1 && data.categories) {
                var categoryHtml = '';
                data.categories.forEach(function(category) {
                    categoryHtml += '<option value="' + self.escapeHtml(category) + '">' + self.escapeHtml(category) + '</option>';
                });
                $categorySelect.append(categoryHtml);
            }

            if ($classSelect.find('option').length <= 1 && data.classes) {
                var classHtml = '';
                data.classes.forEach(function(cls) {
                    classHtml += '<option value="' + self.escapeHtml(cls) + '">' + self.escapeHtml(cls) + '</option>';
                });
                $classSelect.append(classHtml);
            }

            if ($yearSelect.find('option').length <= 1 && data.years) {
                var yearHtml = '';
                data.years.forEach(function(year) {
                    yearHtml += '<option value="' + self.escapeHtml(year) + '">' + self.escapeHtml(year) + '</option>';
                });
                $yearSelect.append(yearHtml);
            }

            if ($ceremonySelect.find('option').length <= 1 && data.ceremonies) {
                var ceremonyHtml = '';
                data.ceremonies.forEach(function(ceremony) {
                    ceremonyHtml += '<option value="' + self.escapeHtml(ceremony) + '">' + self.escapeHtml(self.getOrdinal(ceremony) + ' Academy Awards') + '</option>';
                });
                $ceremonySelect.append(ceremonyHtml);
            }

            // Reflect any shortcode-provided initial filters in the UI.
            if (self.config.currentFilters.category) $categorySelect.val(self.config.currentFilters.category);
            if (self.config.currentFilters.class) $classSelect.val(self.config.currentFilters.class);
            if (self.config.currentFilters.year) $yearSelect.val(self.config.currentFilters.year);
            if (self.config.currentFilters.ceremony) $ceremonySelect.val(self.config.currentFilters.ceremony);
            $('#aat-filter-winners').prop('checked', self.config.currentFilters.winnersOnly);
        },

        // Initialize DataTable (server-side)
        initDataTableServerSide: function() {
            const self = this;

            if (!$.fn || !$.fn.DataTable) {
                self.showError('Data Explorer could not load its table engine. Refresh the page, or use Poster View while the table assets reload.');
                return;
            }

            if (this.config.dataTable) {
                this.config.dataTable.destroy();
            }

            this.hideLoading();

            const isMobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;

            this.config.dataTable = $(this.config.tableSelector).DataTable({
                processing: true,
                serverSide: true,
                deferRender: true,
                searchDelay: 400,
                autoWidth: false,
                pageLength: isMobile ? 8 : 25,
                lengthMenu: isMobile ? [[8, 16, 32], [8, 16, 32]] : [[10, 25, 50, 100], [10, 25, 50, 100]],
                dom: isMobile ? '<"aat-table-top"f>rt<"aat-table-bottom"ip>' : '<"aat-table-top"lf>rt<"aat-table-bottom"ip>',
                responsive: {
                    details: {
                        type: 'column',
                        target: 0
                    }
                },
                ajax: function(dtRequest, callback) {
                    // Attach our custom filters to the DataTables request payload.
                    dtRequest.action = 'aat_get_awards_datatable';
                    dtRequest.nonce = aatData.nonce;
                    dtRequest.category = self.config.currentFilters.category;
                    dtRequest.class = self.config.currentFilters.class;
                    dtRequest.year = self.config.currentFilters.year;
                    dtRequest.ceremony = self.config.currentFilters.ceremony;
                    dtRequest.winners_only = self.config.currentFilters.winnersOnly ? 'true' : 'false';

                    $.ajax({
                        url: aatData.ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: dtRequest,
                        success: function(resp) {
                            if (resp && typeof resp.recordsTotal !== 'undefined' && resp.data) {
                                // Update stats bar using server-provided counts.
                                if (resp.stats) {
                                    self.updateFilteredStats(resp.stats);
                                } else {
                                    self.updateFilteredStats({
                                        filtered_total: resp.recordsFiltered,
                                        filtered_winners: null
                                    });
                                }
                                callback(resp);
                            } else {
                                self.showError('Failed to load data');
                                callback({ draw: dtRequest.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                            }
                        },
                        error: function() {
                            self.showError('Server error. Please try again.');
                            callback({ draw: dtRequest.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                        }
                    });
                },
                columns: [
                    // Responsive details control column (tap-friendly on mobile)
                    {
                        title: '',
                        data: null,
                        defaultContent: '',
                        orderable: false,
                        className: 'dtr-control aat-col-control',
                        width: '28px',
                        responsivePriority: 1
                    },
                    {
                        title: '#',
                        data: 'ceremony',
                        className: 'aat-col-ceremony',
                        width: '62px',
                        responsivePriority: 6,
                        render: function(data) {
                            const n = parseInt(data, 10);
                            const url = (aatData && aatData.entityBase) ? (aatData.entityBase + 'ceremony/' + n + '/') : '';
                            const label = self.getOrdinal(n);
                            if (url && n > 0) {
                                return '<a class="aat-hub-link aat-ceremony" href="' + url + '" title="View ceremony">' + label + '</a>';
                            }
                            return '<span class="aat-ceremony">' + label + '</span>';
                        }
                    },
                    {
                        title: 'Year',
                        data: 'year',
                        className: 'aat-col-year',
                        width: '90px',
                        responsivePriority: 2,
                        render: function(data) {
                            return '<span class="aat-year">' + self.escapeHtml(data) + '</span>';
                        }
                    },
                    {
                        title: 'Category',
                        data: null,
                        className: 'aat-col-category',
                        responsivePriority: 3,
                        render: function(_data, _type, row) {
                            const cat = row.canonical_category || row.category || '';
                            const slug = row.category_slug || '';
                            const url = (aatData && aatData.entityBase && slug) ? (aatData.entityBase + 'category/' + slug + '/') : '';
                            const pill = '<span class="aat-category-pill">' + self.escapeHtml(self.formatCategory(cat)) + '</span>';
                            if (url) {
                                return '<a class="aat-hub-link" href="' + url + '" title="View category">' + pill + '</a>';
                            }
                            return pill;
                        }
                    },
                    {
                        title: 'Nominee',
                        data: null,
                        className: 'aat-col-nominee',
                        responsivePriority: 4,
                        render: function(_data, _type, row) {
                            return self.renderNomineeCell(row);
                        }
                    },
                    {
                        title: 'Film',
                        data: null,
                        className: 'aat-col-film',
                        responsivePriority: 5,
                        render: function(_data, _type, row) {
                            return self.renderFilmCell(row);
                        }
                    },
                    {
                        title: 'Status',
                        data: 'winner',
                        className: 'aat-col-status',
                        width: '110px',
                        responsivePriority: 7,
                        render: function(data) {
                            if (data == 1 || data === true || data === 'true' || data === '1') {
                                return '<span class="aat-winner-badge">Winner</span>';
                            }
                            return '<span class="aat-nominee-badge">Nominee</span>';
                        }
                    },
                    {
                        title: 'Role',
                        data: 'detail',
                        className: 'aat-col-detail',
                        visible: false,
                        orderable: false,
                        render: function(data) {
                            return '<span class="aat-detail">' + self.escapeHtml(data || '') + '</span>';
                        }
                    },
                    {
                        title: 'Note',
                        data: 'note',
                        className: 'aat-col-note',
                        visible: false,
                        orderable: false,
                        render: function(data) {
                            return '<span class="aat-note">' + self.escapeHtml(data || '') + '</span>';
                        }
                    },
                    {
                        title: 'Citation',
                        data: 'citation',
                        className: 'aat-col-citation',
                        visible: false,
                        orderable: false,
                        render: function(data) {
                            return '<span class="aat-citation">' + self.escapeHtml(data || '') + '</span>';
                        }
                    }
                ],
                order: [[1, 'desc'], [3, 'asc'], [6, 'desc']],
                language: {
                    search: 'Search:',
                    searchPlaceholder: 'Search nominees, films, categories…',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ nominations',
                    infoEmpty: 'No nominations found',
                    infoFiltered: '(filtered from _MAX_ total)',
                    processing: 'Loading…',
                    paginate: { first: '««', previous: '‹', next: '›', last: '»»' },
                    emptyTable: 'No Academy Awards data available. Import data in admin panel.'
                },
                createdRow: function(rowEl, rowData) {
                    if (rowData && (rowData.winner == 1 || rowData.winner === true || rowData.winner === 'true' || rowData.winner === '1')) {
                        $(rowEl).addClass('winner-row');
                    }
                },
                initComplete: function() {
                    $(self.config.tableSelector).addClass('aat-animate-in');
                }
            });

            // Apply any shortcode-provided initial filters immediately.
            this.applyFilters();
        },

        /**
         * Escape HTML to prevent injection (even though the dataset is curated).
         */
        escapeHtml: function(input) {
            const str = (input === undefined || input === null) ? '' : String(input);
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        splitPipe: function(input) {
            if (!input) return [];
            return String(input)
                .split('|')
                .map(s => s.trim())
                .filter(Boolean);
        },

        buildImdbUrl: function(id) {
            if (!id) return '';
            const s = String(id).trim();
            if (/^tt\d+$/.test(s)) return 'https://www.imdb.com/title/' + s + '/';
            if (/^nm\d+$/.test(s)) return 'https://www.imdb.com/name/' + s + '/';
            if (/^co\d+$/.test(s)) return 'https://www.imdb.com/company/' + s + '/';
            return '';
        },

        buildEntityUrl: function(id) {
            if (!id) return '';
            const base = (window.aatData && aatData.entityBase) ? String(aatData.entityBase) : '';
            if (!base) return '';
            const s = String(id).trim();
            if (/^tt\d+$/.test(s)) return base + 'title/' + s + '/';
            if (/^(nm\d+|lnm-[a-z0-9-]+)$/.test(s)) return base + 'name/' + s + '/';
            if (/^co\d+$/.test(s)) return base + 'company/' + s + '/';
            return '';
        },

        joinHumanHtml: function(items) {
            if (!items || !items.length) return '';
            if (items.length === 1) return items[0];
            if (items.length === 2) return items[0] + ' <span class="aat-conj">&amp;</span> ' + items[1];
            return items.slice(0, -1).join(', ') + ' <span class="aat-conj">&amp;</span> ' + items[items.length - 1];
        },

        renderProfilePills: function(ids) {
            const self = this;
            if (!ids || !ids.length) return '';

            let html = '<span class="aat-imdb-pills">';
            ids.forEach(function(id, idx) {
                const url = self.buildEntityUrl(id);
                if (!url) return;

                const label = (ids.length > 1) ? ('Lunara ' + (idx + 1)) : 'Lunara';
                html += '<a class="aat-imdb-pill" href="' + url + '" aria-label="Open ' + self.escapeHtml(label) + '">' + self.escapeHtml(label) + '</a>';
            });
            html += '</span>';
            return html;
        },

        isTitlePrimaryNomineeRow: function(row) {
            const classLabel = row && row.class ? String(row.class).trim().toUpperCase() : '';
            const category = row && (row.canonical_category || row.category) ? String(row.canonical_category || row.category).trim().toUpperCase() : '';

            if (classLabel === 'TITLE') return true;

            return (
                category.indexOf('INTERNATIONAL FEATURE FILM') === 0 ||
                category.indexOf('DOCUMENTARY') === 0 ||
                category.indexOf('SHORT FILM') === 0 ||
                category.indexOf('SHORT SUBJECT') === 0 ||
                category.indexOf('SPECIAL FOREIGN LANGUAGE FILM AWARD') === 0
            );
        },

        normalizeComparableName: function(value) {
            if (!value) return '';
            let normalized = String(value).trim();
            if (normalized.normalize) {
                normalized = normalized.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
            }

            return normalized
                .toLowerCase()
                .replace(/&/g, ' and ')
                .replace(/[^a-z0-9]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        },

        normalizeCreditPeople: function(value) {
            return this.normalizeComparableName(value)
                .replace(/\band\b/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        },

        matchedNomineeIdsForRow: function(row) {
            const ids = this.splitPipe(row && row.nominee_ids ? row.nominee_ids : '');
            const nominees = this.splitPipe(row && row.nominees ? row.nominees : '');
            const explicitName = row && row.name ? String(row.name).trim() : '';

            if (!explicitName || ids.length < 2 || ids.length !== nominees.length) {
                return ids;
            }

            const target = this.normalizeComparableName(explicitName);
            if (!target) {
                return ids;
            }

            const matched = ids.filter((id, index) => this.normalizeComparableName(nominees[index]) === target);
            return matched.length === 1 ? matched : ids;
        },

        renderNomineeCell: function(row) {
            const nominees = this.splitPipe(row && row.nominees ? row.nominees : '');
            const ids = this.splitPipe(row && row.nominee_ids ? row.nominee_ids : '');
            const officialCredit = row && row.name ? String(row.name).trim() : '';
            const category = row && (row.canonical_category || row.category) ? String(row.canonical_category || row.category).trim().toUpperCase() : '';
            const structuredCreditCategory = category.indexOf('WRITING (') === 0 || category.indexOf('MUSIC (ORIGINAL SONG') === 0 || category.indexOf('MUSIC (ORIGINAL SCORE') === 0;
            let identityHtml = '';

            if (nominees.length && nominees.length === ids.length) {
                identityHtml = nominees.map((nominee, index) => {
                    const url = this.buildEntityUrl(ids[index]);
                    return url
                        ? '<a class="aat-imdb-link aat-nominee-link" href="' + url + '">' + this.escapeHtml(nominee) + '</a>'
                        : '<span class="aat-nominee-name">' + this.escapeHtml(nominee) + '</span>';
                }).join('<span class="aat-credit-separator" aria-hidden="true"> · </span>');
            } else if (nominees.length) {
                identityHtml = '<span class="aat-nominee-name">' + this.escapeHtml(nominees.join(', ')) + '</span>' + this.renderProfilePills(ids);
            } else if (this.isTitlePrimaryNomineeRow(row) && row && row.film) {
                identityHtml = '<span class="aat-nominee-name">' + this.escapeHtml(String(row.film).replace(/\|/g, ', ')) + '</span>';
            } else if (officialCredit) {
                identityHtml = '<span class="aat-nominee-name">' + this.escapeHtml(officialCredit) + '</span>' + this.renderProfilePills(ids);
            }

            if (!identityHtml) return '<span class="aat-no-film">—</span>';

            const peopleLabel = nominees.join(', ');
            if (structuredCreditCategory && officialCredit && this.normalizeCreditPeople(officialCredit) !== this.normalizeCreditPeople(peopleLabel)) {
                identityHtml += '<span class="aat-credit-line">' + this.escapeHtml(officialCredit) + '</span>';
            }

            return identityHtml;
        },

        linkifyNomineeNames: function(nameRaw, ids) {
            if (!ids || ids.length < 2) return '';

            // Detect role suffix (", Producer(s)", ", Director(s)", etc.)
            let namesPart = nameRaw;
            let roleSuffix = '';
            const lastComma = nameRaw.lastIndexOf(',');
            if (lastComma !== -1) {
                const possible = nameRaw.slice(lastComma + 1).trim();
                if (possible && possible.length <= 40 && /(producer|producers|director|directors|writer|writers|composer|composers|editor|editors|presenter|presenters)/i.test(possible)) {
                    roleSuffix = possible;
                    namesPart = nameRaw.slice(0, lastComma).trim();
                }
            }

            const normalized = namesPart.replace(/\s*&\s*/g, ' and ');
            const chunks = normalized.split(',').map(s => s.trim()).filter(Boolean);

            let names = [];
            chunks.forEach(function(chunk) {
                chunk.split(/\s+and\s+/i).forEach(function(part) {
                    const t = part.trim();
                    if (t) names.push(t);
                });
            });

            if (names.length !== ids.length) return '';

            const linkedNames = names.map((n, i) => {
                const url = this.buildEntityUrl(ids[i]);
                const label = this.escapeHtml(n);
                if (url) {
                    return '<a class="aat-imdb-link aat-nominee-link" href="' + url + '">' + label + '</a>';
                }
                return '<span class="aat-nominee-name">' + label + '</span>';
            });

            const joined = this.joinHumanHtml(linkedNames);
            const suffix = roleSuffix ? '<span class="aat-role-suffix">, ' + this.escapeHtml(roleSuffix) + '</span>' : '';
            return '<span class="aat-nominee-name">' + joined + suffix + '</span>';
        },

        renderFilmCell: function(row) {
            const titles = this.splitPipe(row.film);
            const ids = this.splitPipe(row.film_id);

            if (!titles.length) return '<span class="aat-no-film">—</span>';

            let body = '';
            if (ids.length && ids.length === titles.length) {
                const linkedTitles = titles.map((t, i) => {
                    const url = this.buildEntityUrl(ids[i]);
                    const label = this.escapeHtml(t);
                    if (url) {
                        return '<a class="aat-imdb-link aat-film-link" href="' + url + '">' + label + '</a>';
                    }
                    return '<span class="aat-film-title">' + label + '</span>';
                });
                body = linkedTitles.join('<span class="aat-sep"> · </span>');
                const reviewChip = row.review_url ? (' <a class="aat-review-chip" href="' + row.review_url + '">Review</a>') : '';
                return '<span class="aat-film-cell">' + body + reviewChip + '</span>';
            }

            // If the number of IDs doesn't match, show the text + profile pills.
            const text = titles.map(t => '<span class="aat-film-title">' + this.escapeHtml(t) + '</span>').join('<span class="aat-sep"> · </span>');
            const reviewChip2 = row.review_url ? (' <a class="aat-review-chip" href="' + row.review_url + '">Review</a>') : '';
            return '<span class="aat-film-cell">' + text + this.renderProfilePills(ids) + reviewChip2 + '</span>';
        },

        formatCategory: function(category) {
            if (!category) return '';
            return category
                .replace('ACTOR IN A LEADING ROLE', 'Best Actor')
                .replace('ACTRESS IN A LEADING ROLE', 'Best Actress')
                .replace('ACTOR IN A SUPPORTING ROLE', 'Best Supporting Actor')
                .replace('ACTRESS IN A SUPPORTING ROLE', 'Best Supporting Actress')
                .replace('BEST PICTURE', 'Best Picture')
                .replace('DIRECTING', 'Best Director');
        },
        applyFilters: function() {
            if (!this.config.dataTable) return;

            // Decade quick filter is implemented as a Year column search prefix.
            if (this.config.currentFilters.decadePrefix && !this.config.currentFilters.year) {
                this.config.dataTable.columns(2).search(this.config.currentFilters.decadePrefix);
            } else {
                this.config.dataTable.columns(2).search('');
            }

            // Reset to first page when filters change
            this.config.dataTable.ajax.reload(null, true);
        },

        resetFilters: function() {
            this.config.currentFilters = {
                category: '',
                class: '',
                year: '',
                ceremony: '',
                winnersOnly: false,
                decadePrefix: ''
            };

            $('#aat-filter-category, #aat-filter-class, #aat-filter-year, #aat-filter-ceremony').val('');
            $('#aat-filter-winners').prop('checked', false);
            $('.aat-quick-filter').removeClass('active');

            if (this.config.dataTable) {
                // Clear DataTables search inputs
                this.config.dataTable.search('');
                this.config.dataTable.columns().search('');
                this.config.dataTable.ajax.reload(null, true);
            }
        },

        filterByDecade: function(decade) {
            const decadePrefix = String(decade || '').substring(0, 3);
            this.config.currentFilters.decadePrefix = decadePrefix;
            // Clear exact year filter
            this.config.currentFilters.year = '';
            $('#aat-filter-year').val('');
            this.applyFilters();
        },

        initQuickFilters: function() {
            const cats = [
                { label: 'Best Picture', value: 'BEST PICTURE' },
                { label: 'Director', value: 'DIRECTING' },
                { label: 'Actor', value: 'ACTOR IN A LEADING ROLE' },
                { label: 'Actress', value: 'ACTRESS IN A LEADING ROLE' },
                { label: 'Sup. Actor', value: 'ACTOR IN A SUPPORTING ROLE' },
                { label: 'Sup. Actress', value: 'ACTRESS IN A SUPPORTING ROLE' }
            ];
            const $qf = $('.aat-quick-filters');
            $qf.empty();
            cats.forEach(function(c) {
                $qf.append('<button class="aat-quick-filter" data-filter="category" data-value="' + c.value + '">' + c.label + '</button>');
            });
            $qf.append('<span class="aat-filter-divider">|</span>');
            ['2020s', '2010s', '2000s', '1990s', '1980s', '1970s'].forEach(function(d) {
                $qf.append('<button class="aat-quick-filter" data-filter="decade" data-value="' + d + '">' + d + '</button>');
            });
        },

        updateMetaStats: function(meta) {
            if (!meta) return;

            const totals = meta.totals || {};
            const totalRecords = (typeof totals.records === 'number') ? totals.records : 0;
            const totalWinners = (typeof totals.winners === 'number') ? totals.winners : 0;
            const totalCategories = (typeof totals.categories === 'number') ? totals.categories : (meta.categories ? meta.categories.length : 0);
            const totalCeremonies = (typeof totals.ceremonies === 'number') ? totals.ceremonies : (meta.ceremonies ? meta.ceremonies.length : 0);

            this.config.metaTotals.categories = totalCategories;
            this.config.metaTotals.ceremonies = totalCeremonies;

            $('#aat-stat-categories').text(this.formatNumber(totalCategories));
            $('#aat-stat-ceremonies').text(this.formatNumber(totalCeremonies));

            // Provide an immediate value before the first server-side draw returns.
            if ($('#aat-stat-total').text() === '—') {
                $('#aat-stat-total').text(this.formatNumber(totalRecords));
            }
            if ($('#aat-stat-winners').text() === '—') {
                $('#aat-stat-winners').text(this.formatNumber(totalWinners));
            }
        },

        updateFilteredStats: function(stats) {
            if (!stats) return;

            const filteredTotal = (stats.filtered_total !== undefined && stats.filtered_total !== null) ? stats.filtered_total : null;
            const filteredWinners = (stats.filtered_winners !== undefined && stats.filtered_winners !== null) ? stats.filtered_winners : null;

            if (filteredTotal !== null) {
                $('#aat-stat-total').text(this.formatNumber(filteredTotal));
            }
            if (filteredWinners !== null) {
                $('#aat-stat-winners').text(this.formatNumber(filteredWinners));
            }
        },

        showLoading: function() {
            $('.aat-table-wrapper').html('<div class="aat-loading"><div class="aat-loading-spinner"></div><span class="aat-loading-text">Loading Academy Awards data...</span></div>');
        },

        hideLoading: function() {
            $('.aat-table-wrapper').html('<table id="aat-awards-table" class="aat-datatable display responsive" width="100%"></table>');
        },

        showError: function(message) {
            $('.aat-table-wrapper').html('<div class="aat-no-results"><div class="aat-no-results-icon">🎬</div><h3>No Data Found</h3><p>' + this.escapeHtml(message) + '</p><button class="aat-btn aat-btn-primary" onclick="location.reload()">Try Again</button></div>');
        },

        formatNumber: function(num) {
            const n = parseInt(num, 10);
            if (isNaN(n)) return '0';
            return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        getOrdinal: function(n) {
            n = parseInt(n, 10);
            if (isNaN(n)) return '';
            const s = ['th', 'st', 'nd', 'rd'];
            const v = n % 100;
            return n + (s[(v - 20) % 10] || s[v] || s[0]);
        }
    };

    window.AcademyAwardsTable = AcademyAwardsTable;

    $(document).ready(function() {
        if ($(AcademyAwardsTable.config.containerSelector).length > 0) {
            AcademyAwardsTable.init();
        }
    });

})(jQuery);
