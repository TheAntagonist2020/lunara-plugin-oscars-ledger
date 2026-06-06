(function () {
    'use strict';

    function getState(root) {
        var key = root.getAttribute('data-state-key') || 'aat-ballot';
        try {
            return JSON.parse(localStorage.getItem(key) || '{}');
        } catch (error) {
            return {};
        }
    }

    function setState(root, state) {
        var key = root.getAttribute('data-state-key') || 'aat-ballot';
        localStorage.setItem(key, JSON.stringify(state));
    }

    function restore(root) {
        var state = getState(root);
        root.querySelectorAll('input[type="radio"][data-ballot-kind]').forEach(function (input) {
            var category = input.getAttribute('data-ballot-category');
            var kind = input.getAttribute('data-ballot-kind');
            if (state[category] && state[category][kind] === input.value) {
                input.checked = true;
            }
        });
    }

    function copy(root) {
        var state = getState(root);
        var lines = [];
        Object.keys(state).sort().forEach(function (category) {
            var item = state[category];
            lines.push(category);
            if (item.willLabel) {
                lines.push('Will Win: ' + item.willLabel);
            }
            if (item.shouldLabel) {
                lines.push('Should Win: ' + item.shouldLabel);
            }
            lines.push('');
        });
        var text = lines.join('\n').trim();
        if (!text) {
            return Promise.resolve(false);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () { return true; });
        }
        return Promise.resolve(false);
    }

    document.addEventListener('change', function (event) {
        var input = event.target.closest('input[type="radio"][data-ballot-kind]');
        if (!input) {
            return;
        }
        var root = input.closest('.aat-ballot');
        var category = input.getAttribute('data-ballot-category');
        var kind = input.getAttribute('data-ballot-kind');
        var state = getState(root);
        state[category] = state[category] || {};
        state[category][kind] = input.value;
        state[category][kind + 'Label'] = input.getAttribute('data-ballot-label') || '';
        setState(root, state);
    });

    document.addEventListener('click', function (event) {
        var copyButton = event.target.closest('[data-aat-ballot-copy]');
        var resetButton = event.target.closest('[data-aat-ballot-reset]');
        var root = event.target.closest('.aat-ballot');
        if (!root) {
            return;
        }
        var status = root.querySelector('.aat-ballot__status');

        if (copyButton) {
            copy(root).then(function (ok) {
                if (status) {
                    status.textContent = ok ? (window.aatBallot && aatBallot.copySuccess ? aatBallot.copySuccess : 'Ballot copied.') : (window.aatBallot && aatBallot.copyError ? aatBallot.copyError : 'Nothing to copy yet.');
                }
            });
        }

        if (resetButton) {
            var message = window.aatBallot && aatBallot.resetConfirm ? aatBallot.resetConfirm : 'Clear ballot picks?';
            if (!window.confirm(message)) {
                return;
            }
            localStorage.removeItem(root.getAttribute('data-state-key') || 'aat-ballot');
            root.querySelectorAll('input[type="radio"][data-ballot-kind]').forEach(function (input) {
                input.checked = false;
            });
            if (status) {
                status.textContent = 'Ballot reset.';
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.aat-ballot').forEach(restore);
        document.querySelectorAll('[data-aat-ballot-ceremony]').forEach(function (select) {
            select.addEventListener('change', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('ceremony', select.value);
                window.location.href = url.toString();
            });
        });
    });
})();
