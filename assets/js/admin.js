/**
 * Academy Awards Table - Admin JavaScript
 * Handles data import and management
 */

(function($) {
    'use strict';

    const AATAdmin = {

        init: function() {
            this.bindEvents();
            this.initDragDrop();
            this.initTrackerAdmin();
            this.initPosterAdmin();
        },

        bindEvents: function() {
            const self = this;

            // File input change
            $('#aat-import-file').on('change', function() {
                self.handleFileSelect(this.files[0]);
            });

            // Upload button click
            $('.aat-import-btn-upload').on('click', function() {
                $('#aat-import-file').click();
            });

            // Import bundled dataset
            $('#aat-import-bundled').on('click', function() {
                if (!confirm('Import the bundled oscars.csv into your database? This will replace any existing data.')) {
                    return;
                }
                self.showProgress();
                self.updateProgress(0, 'Starting import...');
                self.importBundled(0);
            });


            // Delta import (replace a single ceremony only)
            $('#aat-delta-import').on('click', function() {
                const fileInput = $('#aat-delta-file')[0];
                const file = fileInput && fileInput.files ? fileInput.files[0] : null;

                if (!file) {
                    self.showMessage('error', 'Please choose a TSV/CSV file first.');
                    return;
                }

                if (!confirm('Replace the ceremony contained in this file? Only that ceremony will be replaced.')) {
                    return;
                }

                self.showProgress();
                self.updateProgress(10, 'Uploading delta file...');
                self.importCeremonyDelta(file);
            });

            // Repair schema / rewrite rules
            $('#aat-repair-schema').on('click', function() {
                if (!confirm('Run a quick repair? This will ensure database tables + rewrite rules are up to date.')) {
                    return;
                }
                self.repairSchema();
            });


            // Clear data button
            $('#aat-clear-data').on('click', function() {
                if (confirm('Are you sure you want to delete ALL Academy Awards data? This cannot be undone!')) {
                    self.clearAllData();
                }
            });

            // Download sample
            $('.aat-download-sample').on('click', function(e) {
                e.preventDefault();
                self.downloadSample($(this).data('format'));
            });
        },

        initDragDrop: function() {
            const self = this;
            const $dropZone = $('.aat-import-area');

            $dropZone.on('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('dragover');
            });

            $dropZone.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('dragover');
            });

            $dropZone.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('dragover');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    self.handleFileSelect(files[0]);
                }
            });
        },

        handleFileSelect: function(file) {
            const self = this;
            const validTypes = ['text/csv', 'application/json', 'text/plain'];
            const validExtensions = ['csv', 'json'];
            
            const extension = file.name.split('.').pop().toLowerCase();
            
            if (!validExtensions.includes(extension)) {
                this.showMessage('error', 'Invalid file type. Please upload a CSV or JSON file.');
                return;
            }

            this.showProgress();
            this.uploadFile(file);
        },

        uploadFile: function(file) {
            const self = this;
            const formData = new FormData();
            
            formData.append('action', 'aat_import_data');
            formData.append('nonce', aatAdmin.nonce);
            formData.append('file', file);

            $.ajax({
                url: aatAdmin.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = (e.loaded / e.total) * 100;
                            self.updateProgress(percent);
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    self.hideProgress();
                    
                    if (response.success) {
                        self.showMessage('success', response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        self.showMessage('error', response.data || 'Import failed. Please check your file format.');
                    }
                },
                error: function() {
                    self.hideProgress();
                    self.showMessage('error', 'Server error. Please try again.');
                }
            });
        },

                importBundled: function(offset) {
            const self = this;

            // Track retries across requests
            if (typeof self._bundledRetries === 'undefined') {
                self._bundledRetries = 0;
            }

            $.ajax({
                url: aatAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aat_import_bundled_data',
                    nonce: aatAdmin.nonce,
                    offset: offset
                },
                success: function(response) {
                    if (!response.success) {
                        self.hideProgress();
                        self.showMessage('error', response.data || 'Bundled import failed.');
                        return;
                    }

                    // Reset retries after a successful response
                    self._bundledRetries = 0;

                    const d = response.data;
                    const pct = d.total_rows > 0 ? (d.offset / d.total_rows) * 100 : 0;
                    self.updateProgress(pct, 'Imported ' + d.offset.toLocaleString() + ' / ' + d.total_rows.toLocaleString());

                    if (d.done) {
                        self.hideProgress();
                        self.showMessage('success', d.message || 'Import complete.');
                        setTimeout(function() { location.reload(); }, 1200);
                        return;
                    }

                    // Slight delay to reduce load / rate limiting on managed hosts
                    setTimeout(function() { self.importBundled(d.offset); }, 400);
                },
                error: function() {
                    self._bundledRetries++;

                    // Auto-retry a few times (WordPress.com can throttle bursty admin-ajax calls)
                    if (self._bundledRetries <= 10) {
                        const waitMs = 800 * self._bundledRetries;
                        self.showMessage('warning', 'Temporary server slowdown… retrying in ' + Math.round(waitMs / 1000) + 's (attempt ' + self._bundledRetries + '/10).');
                        setTimeout(function() { self.importBundled(offset); }, waitMs);
                        return;
                    }

                    self.hideProgress();

                    // Offer a manual resume button at the current offset
                    const resumeHtml =
                        'Import paused at <strong>' + offset.toLocaleString() + '</strong> rows. ' +
                        '<button type="button" class="button button-primary" id="aat-resume-import" data-offset="' + offset + '">Resume import</button>';

                    self.showMessage('error', resumeHtml);

                    // Bind resume click (delegate so it works even after message re-renders)
                    $('.aat-message-area').off('click', '#aat-resume-import').on('click', '#aat-resume-import', function() {
                        const nextOffset = parseInt($(this).data('offset'), 10) || 0;
                        self._bundledRetries = 0;
                        self.showProgress();
                        self.importBundled(nextOffset);
                    });
                }
            });
        },


        importCeremonyDelta: function(file) {
            const self = this;
            const formData = new FormData();
            formData.append('action', 'aat_import_ceremony_delta');
            formData.append('nonce', aatAdmin.nonce);
            formData.append('delta_file', file);

            $.ajax({
                url: aatAdmin.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(resp) {
                    if (resp && resp.success) {
                        self.updateProgress(100, resp.data && resp.data.message ? resp.data.message : 'Delta import complete.');
                        self.hideProgress();
                        self.showMessage('success', resp.data && resp.data.message ? resp.data.message : 'Delta import complete.');
                        setTimeout(function() { location.reload(); }, 1200);
                        return;
                    }

                    self.hideProgress();
                    self.showMessage('error', (resp && resp.data) ? resp.data : 'Delta import failed.');
                },
                error: function() {
                    self.hideProgress();
                    self.showMessage('error', 'Server error during delta import.');
                }
            });
        },

        repairSchema: function() {
            const self = this;
            self.showProgress();
            self.updateProgress(20, 'Repairing schema...');

            $.ajax({
                url: aatAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aat_repair_schema',
                    nonce: aatAdmin.nonce
                },
                success: function(resp) {
                    self.hideProgress();

                    if (resp && resp.success) {
                        self.showMessage('success', (resp.data && resp.data.message) ? resp.data.message : 'Repair complete.');
                        return;
                    }

                    self.showMessage('error', (resp && resp.data) ? resp.data : 'Repair failed.');
                },
                error: function() {
                    self.hideProgress();
                    self.showMessage('error', 'Server error during repair.');
                }
            });
        },


        clearAllData: function() {
            const self = this;

            $.ajax({
                url: aatAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aat_clear_data',
                    nonce: aatAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('success', 'All data has been cleared.');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        self.showMessage('error', 'Failed to clear data.');
                    }
                },
                error: function() {
                    self.showMessage('error', 'Server error. Please try again.');
                }
            });
        },

        downloadSample: function(format) {
            let content, filename, type;

            const sampleData = [
                {
                    ceremony_year: 2024,
                    ceremony_number: 96,
                    category: "Best Picture",
                    nominee: "Oppenheimer",
                    film: "Oppenheimer",
                    winner: true,
                    notes: ""
                },
                {
                    ceremony_year: 2024,
                    ceremony_number: 96,
                    category: "Best Picture",
                    nominee: "Barbie",
                    film: "Barbie",
                    winner: false,
                    notes: ""
                },
                {
                    ceremony_year: 2024,
                    ceremony_number: 96,
                    category: "Best Director",
                    nominee: "Christopher Nolan",
                    film: "Oppenheimer",
                    winner: true,
                    notes: ""
                },
                {
                    ceremony_year: 2024,
                    ceremony_number: 96,
                    category: "Best Actor",
                    nominee: "Cillian Murphy",
                    film: "Oppenheimer",
                    winner: true,
                    notes: ""
                },
                {
                    ceremony_year: 2024,
                    ceremony_number: 96,
                    category: "Best Actress",
                    nominee: "Emma Stone",
                    film: "Poor Things",
                    winner: true,
                    notes: ""
                }
            ];

            if (format === 'json') {
                content = JSON.stringify(sampleData, null, 2);
                filename = 'academy-awards-sample.json';
                type = 'application/json';
            } else {
                // CSV
                const headers = ['ceremony_year', 'ceremony_number', 'category', 'nominee', 'film', 'winner', 'notes'];
                let csv = headers.join(',') + '\n';
                
                sampleData.forEach(function(row) {
                    csv += [
                        row.ceremony_year,
                        row.ceremony_number,
                        '"' + row.category + '"',
                        '"' + row.nominee + '"',
                        '"' + row.film + '"',
                        row.winner,
                        '"' + row.notes + '"'
                    ].join(',') + '\n';
                });
                
                content = csv;
                filename = 'academy-awards-sample.csv';
                type = 'text/csv';
            }

            const blob = new Blob([content], { type: type });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        },

        

        /**
         * Generic entity search (titles/people/companies) from the Oscars dataset.
         * Renders a small suggestion panel; onSelect(item) is called with {id,type,label}.
         */
        initEntitySearch: function($input, $suggestions, onSelect, filterFn) {
            const self = this;
            if (!$input || !$input.length) return;

            let lastQuery = '';
            let timer = null;

            function clear() {
                $suggestions.empty().hide();
            }

            function render(items) {
                if (!items || !items.length) {
                    clear();
                    return;
                }

                const html = items.map(function(it) {
                    const type = (it.type || '').toUpperCase();
                    const badge = '<span class="aat-suggestion-type">' + type + '</span>';
                    return (
                        '<div class="aat-suggestion" data-id="' + it.id + '" data-type="' + it.type + '">' +
                        badge +
                        '<span class="aat-suggestion-label">' + (it.label || it.id) + '</span>' +
                        '<span class="aat-suggestion-id">' + it.id + '</span>' +
                        '</div>'
                    );
                }).join('');

                $suggestions.html(html).show();
            }

            $input.on('input', function() {
                const q = ($input.val() || '').trim();
                if (q.length < 2) { clear(); return; }
                if (q === lastQuery) return;

                lastQuery = q;

                if (timer) clearTimeout(timer);
                timer = setTimeout(function() {
                    $.ajax({
                        url: aatAdmin.ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'aat_tracker_search_entities',
                            nonce: aatAdmin.nonce,
                            q: q
                        },
                        success: function(resp) {
                            if (!resp || !resp.success) { clear(); return; }
                            let items = (resp.data && resp.data.results) ? resp.data.results : [];
                            if (typeof filterFn === 'function') {
                                items = items.filter(filterFn);
                            }
                            render(items);
                        },
                        error: function() {
                            clear();
                        }
                    });
                }, 180);
            });

            // Click to select
            $suggestions.on('click', '.aat-suggestion', function() {
                const $row = $(this);
                const item = {
                    id: $row.data('id'),
                    type: $row.data('type'),
                    label: $row.find('.aat-suggestion-label').text()
                };

                $input.val(item.label);
                clear();

                if (typeof onSelect === 'function') {
                    onSelect(item);
                }
            });

            // Click outside closes
            $(document).on('click', function(e) {
                if ($(e.target).closest($suggestions).length) return;
                if ($(e.target).closest($input).length) return;
                clear();
            });
        },

        /**
         * Tracker V2 admin screen
         */
        initTrackerAdmin: function() {
            const self = this;
            if (!$('.aat-tracker-admin').length) return;

            // Ceremony selector reload
            $('#aat-tracker-ceremony').on('change', function() {
                const ceremony = $(this).val();
                if (!ceremony) return;
                const url = new URL(window.location.href);
                url.searchParams.set('ceremony', ceremony);
                window.location.href = url.toString();
            });

            // Entity search
            self.initEntitySearch(
                $('#aat-tracker-entity-search'),
                $('#aat-tracker-entity-suggestions'),
                function(item) {
                    $('#aat-tracker-entity-id').val(item.id || '');
                    $('#aat-tracker-entity-type').val(item.type || '');
                }
            );

            // Save pick
            $('#aat-tracker-save').on('click', function(e) {
                e.preventDefault();

                const payload = {
                    action: 'aat_tracker_add_pick',
                    nonce: aatAdmin.nonce,
                    ceremony: $('#aat-tracker-ceremony').val(),
                    canonical_category: $('#aat-tracker-category').val(),
                    tier: $('#aat-tracker-tier').val(),
                    rank: $('#aat-tracker-rank').val(),
                    entity_id: $('#aat-tracker-entity-id').val(),
                    entity_type: $('#aat-tracker-entity-type').val(),
                    note: $('#aat-tracker-note').val()
                };

                if (!payload.canonical_category || !payload.entity_id) {
                    alert('Pick needs a Category and a Film/Person/Company.');
                    return;
                }

                $(this).prop('disabled', true).text('Saving…');

                $.ajax({
                    url: aatAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: payload,
                    success: function(resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                            return;
                        }
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.');
                        $('#aat-tracker-save').prop('disabled', false).text('Save Pick');
                    },
                    error: function() {
                        alert('Server error.');
                        $('#aat-tracker-save').prop('disabled', false).text('Save Pick');
                    }
                });
            });

            // Delete pick
            $('.aat-tracker-admin').on('click', '.aat-tracker-delete', function(e) {
                e.preventDefault();
                if (!confirm('Delete this pick?')) return;

                const id = $(this).data('id');
                if (!id) return;

                $.ajax({
                    url: aatAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'aat_tracker_delete_pick',
                        nonce: aatAdmin.nonce,
                        id: id
                    },
                    success: function(resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                            return;
                        }
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Delete failed.');
                    },
                    error: function() {
                        alert('Server error.');
                    }
                });
            });
        },

        /**
         * Poster Library admin screen
         */
        initPosterAdmin: function() {
            const self = this;
            if (!$('.aat-posters-admin').length) return;

            // Entity search (titles only)
            self.initEntitySearch(
                $('#aat-poster-imdb-search'),
                $('#aat-poster-entity-suggestions'),
                function(item) {
                    if (item.type !== 'title') return;
                    $('#aat-poster-imdb-id').val(item.id || '');
                },
                function(item) { return item.type === 'title'; }
            );

            // Media picker
            let frame = null;
            function renderPreview(attachment) {
                if (!attachment || !attachment.url) return;
                $('#aat-poster-preview').html('<img src="' + attachment.url + '" alt="" style="max-width:140px; height:auto; border-radius:10px; border:1px solid rgba(0,0,0,.12);" />');
            }

            $('#aat-poster-pick').on('click', function(e) {
                e.preventDefault();
                if (frame) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: 'Select Poster',
                    button: { text: 'Use this poster' },
                    multiple: false
                });

                frame.on('select', function() {
                    const attachment = frame.state().get('selection').first().toJSON();
                    $('#aat-poster-attachment-id').val(attachment.id);
                    renderPreview(attachment);
                });

                frame.open();
            });

            $('#aat-poster-clear').on('click', function(e) {
                e.preventDefault();
                $('#aat-poster-attachment-id').val('');
                $('#aat-poster-preview').empty();
            });

            // Save mapping
            $('#aat-poster-save').on('click', function(e) {
                e.preventDefault();

                const imdbId = ($('#aat-poster-imdb-id').val() || '').trim().toLowerCase();
                const attachmentId = parseInt($('#aat-poster-attachment-id').val() || '0', 10);

                if (!imdbId || imdbId.indexOf('tt') !== 0) {
                    alert('Please select a film / tt-id first.');
                    return;
                }
                if (!attachmentId) {
                    alert('Please choose an image from the Media Library.');
                    return;
                }

                $(this).prop('disabled', true).text('Saving…');

                $.ajax({
                    url: aatAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'aat_posters_save',
                        nonce: aatAdmin.nonce,
                        imdb_id: imdbId,
                        attachment_id: attachmentId,
                        source: 'manual'
                    },
                    success: function(resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                            return;
                        }
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.');
                        $('#aat-poster-save').prop('disabled', false).text('Save Poster');
                    },
                    error: function() {
                        alert('Server error.');
                        $('#aat-poster-save').prop('disabled', false).text('Save Poster');
                    }
                });
            });

            // Remove mapping
            $('.aat-posters-admin').on('click', '.aat-poster-delete', function(e) {
                e.preventDefault();
                if (!confirm('Remove this poster mapping?')) return;

                const imdbId = $(this).data('imdb');
                if (!imdbId) return;

                $.ajax({
                    url: aatAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'aat_posters_delete',
                        nonce: aatAdmin.nonce,
                        imdb_id: imdbId
                    },
                    success: function(resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                            return;
                        }
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Remove failed.');
                    },
                    error: function() { alert('Server error.'); }
                });
            });

            // Sync from reviews
            $('#aat-posters-sync').on('click', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const $status = $('#aat-posters-sync-status');

                $btn.prop('disabled', true).text('Syncing…');
                $status.text('');

                $.ajax({
                    url: aatAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'aat_posters_sync_from_reviews',
                        nonce: aatAdmin.nonce
                    },
                    success: function(resp) {
                        if (resp && resp.success) {
                            const d = resp.data || {};
                            $status.text('Synced: ' + (d.synced || 0) + ' • Skipped: ' + (d.skipped || 0));
                            setTimeout(function() { window.location.reload(); }, 600);
                            return;
                        }
                        $status.text((resp && resp.data && resp.data.message) ? resp.data.message : 'Sync failed.');
                        $btn.prop('disabled', false).text('Sync posters from published reviews');
                    },
                    error: function() {
                        $status.text('Server error.');
                        $btn.prop('disabled', false).text('Sync posters from published reviews');
                    }
                });
            });
        },
showProgress: function() {
            $('.aat-progress').show();
            this.updateProgress(0);
        },

        hideProgress: function() {
            $('.aat-progress').hide();
        },

        updateProgress: function(percent, text) {
            $('.aat-progress-fill').css('width', percent + '%');
            $('.aat-progress-text').text(text || (Math.round(percent) + '%'));
        },

        showMessage: function(type, message) {
            const $messageArea = $('.aat-message-area');
            $messageArea.html(
                '<div class="aat-message aat-message-' + type + '">' + message + '</div>'
            );

            // Keep warnings/errors visible so the user can act (e.g., resume import).
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    $messageArea.find('.aat-message').fadeOut();
                }, 5000);
            }
        }
    };

    $(document).ready(function() {
        AATAdmin.init();
    });

})(jQuery);
