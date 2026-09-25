/*
 * Oscar Ledger Explorer: instant filtering and the hero search.
 *
 * The page is complete without this file. With it, every filter change,
 * chip, pager or "Explore" link fetches the same URL with fragment=1 and
 * swaps in the server-rendered regions, so there is one renderer (PHP) and
 * every view keeps a real, shareable URL. The search box is an ARIA 1.2
 * combobox over /wp-json/lunara-ledger/v1/search; API text is only ever
 * written with textContent.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-lle-root]');
    if (!root || !window.fetch || !window.history || !window.history.pushState || !window.URLSearchParams || !window.URL) {
        return;
    }

    var base = root.getAttribute('data-lle-base');
    var searchUrl = root.getAttribute('data-lle-search');
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var KIND = { person: 'Person', film: 'Film', company: 'Company', category: 'Category', ceremony: 'Ceremony' };
    var regions = {};
    ['filters', 'chips', 'debrief', 'status', 'results', 'pager'].forEach(function (name) {
        regions[name] = root.querySelector('[data-lle-region="' + name + '"]');
    });

    var pending = null;

    function abortable() {
        return 'AbortController' in window ? new AbortController() : null;
    }

    function fragmentUrl(url) {
        var u = new URL(url, window.location.href);
        u.searchParams.set('fragment', '1');
        return u.toString();
    }

    function setBusy(busy) {
        if (regions.results) {
            regions.results.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
    }

    function load(url, push, focusName) {
        if (pending) {
            pending.abort();
        }
        pending = abortable();
        setBusy(true);
        return fetch(fragmentUrl(url), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: pending ? pending.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (data) {
            var fresh = data && data.regions ? data.regions : {};
            Object.keys(regions).forEach(function (name) {
                if (regions[name] && typeof fresh[name] === 'string') {
                    regions[name].innerHTML = fresh[name];
                }
            });
            if (data.title) {
                document.title = data.title;
            }
            var next = data.url || url;
            if (push) {
                window.history.pushState({ lle: true }, '', next);
            } else {
                window.history.replaceState({ lle: true }, '', next);
            }
            setBusy(false);
            if (focusName && regions.filters) {
                var control = regions.filters.querySelector('[name="' + focusName + '"]');
                if (control) {
                    control.focus({ preventScroll: true });
                }
            }
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            window.location.href = url;
        });
    }

    function scrollToResults() {
        var status = root.querySelector('#lle-status');
        if (!status) {
            return;
        }
        var top = status.getBoundingClientRect().top;
        if (top < 0 || top > window.innerHeight * 0.7) {
            status.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        }
    }

    function urlFromForm(form) {
        var params = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled) {
                return;
            }
            if (el.type === 'checkbox') {
                if (el.checked) {
                    params.set(el.name, el.value);
                }
                return;
            }
            if (el.value !== '') {
                params.set(el.name, el.value);
            }
        });
        var query = params.toString();
        return base + (query ? '?' + query : '');
    }

    function urlFromExplore(explore) {
        var params = new URLSearchParams();
        Object.keys(explore || {}).forEach(function (key) {
            params.set(key, String(explore[key]));
        });
        var query = params.toString();
        return base + (query ? '?' + query : '');
    }

    /* Filters: one change, one fetch. The rules mirror the server's. */

    root.addEventListener('change', function (event) {
        var target = event.target;
        var form = target && target.closest ? target.closest('form[data-lle-filters]') : null;
        if (!form || !target.name) {
            return;
        }
        var clear = function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            if (field) {
                field.value = '';
            }
        };
        if (target.name === 'class') {
            clear('category');
        }
        if (target.name === 'decade') {
            clear('ceremony');
        }
        if (target.name === 'ceremony') {
            clear('decade');
        }
        load(urlFromForm(form), true, target.name);
    });

    root.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && form.matches && form.matches('form[data-lle-filters]')) {
            event.preventDefault();
            load(urlFromForm(form), true);
        }
    });

    root.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[data-lle-nav]') : null;
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        load(link.href, true).then(scrollToResults);
    });

    window.history.replaceState({ lle: true }, '', window.location.href);
    window.addEventListener('popstate', function () {
        load(window.location.href, false);
    });

    /* Hero search: an ARIA 1.2 combobox. */

    var form = root.querySelector('[data-lle-search-form]');
    var input = root.querySelector('#lle-q');
    var list = root.querySelector('#lle-suggest');
    if (!form || !input || !list || !searchUrl) {
        return;
    }

    var results = [];
    var active = -1;
    var timer = null;
    var searching = null;
    var shownFor = '';

    function openList() {
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function closeList() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function highlight(index) {
        var options = list.querySelectorAll('.lle-suggest__item');
        Array.prototype.forEach.call(options, function (option, i) {
            option.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });
        active = index;
        if (options[index]) {
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        }
    }

    // A plate for a result with no artwork: up to two initials, leading
    // articles skipped, the same rule as the server's plates.
    function initials(result) {
        if (result.type === 'category' || result.type === 'ceremony') {
            return '\u2605';
        }
        var words = String(result.name || '').split(/[^\p{L}\p{N}]+/u).filter(Boolean);
        if (words.length > 1 && /^(the|a|an)$/i.test(words[0])) {
            words.shift();
        }
        if (!words.length) {
            return '\u2605';
        }
        var mark = words[0].charAt(0);
        if (words.length > 1) {
            mark += words[words.length - 1].charAt(0);
        }
        return mark.toUpperCase();
    }

    function thumb(result) {
        var box = document.createElement('span');
        box.className = 'lle-suggest__thumb';
        box.setAttribute('aria-hidden', 'true');
        if (typeof result.image === 'string' && /^https:\/\//.test(result.image)) {
            var img = document.createElement('img');
            img.alt = '';
            img.width = 32;
            img.height = 48;
            img.decoding = 'async';
            img.src = result.image;
            box.appendChild(img);
        } else {
            box.className += ' is-plate';
            box.textContent = initials(result);
        }
        return box;
    }

    function meta(result) {
        if (!result.nominations) {
            return '';
        }
        var text = result.nominations + (result.nominations === 1 ? ' nomination' : ' nominations');
        if (result.wins) {
            text += ' · ' + result.wins + (result.wins === 1 ? ' win' : ' wins');
        }
        return text;
    }

    function render(found, query) {
        results = found;
        shownFor = query;
        active = -1;
        list.textContent = '';
        if (!found.length) {
            var empty = document.createElement('li');
            empty.className = 'lle-suggest__empty';
            empty.textContent = 'No person, film, company, category or year matches.';
            list.appendChild(empty);
            openList();
            return;
        }
        found.forEach(function (result, index) {
            var option = document.createElement('li');
            option.id = 'lle-option-' + index;
            option.className = 'lle-suggest__item';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.appendChild(thumb(result));
            [['lle-suggest__kind', KIND[result.type] || result.type], ['lle-suggest__name', result.name], ['lle-suggest__meta', meta(result)]].forEach(function (part) {
                var span = document.createElement('span');
                span.className = part[0];
                span.textContent = part[1];
                option.appendChild(span);
            });
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                choose(index);
            });
            list.appendChild(option);
        });
        openList();
    }

    function suggest(query) {
        if (searching) {
            searching.abort();
        }
        searching = abortable();
        var url = new URL(searchUrl, window.location.href);
        url.searchParams.set('q', query);
        url.searchParams.set('limit', '8');
        fetch(url.toString(), { headers: { Accept: 'application/json' }, signal: searching ? searching.signal : undefined })
            .then(function (response) {
                return response.ok ? response.json() : { results: [] };
            })
            .then(function (data) {
                if (input.value.trim() === query) {
                    render((data && data.results) || [], query);
                }
            })
            .catch(function () {});
    }

    function choose(index) {
        var result = results[index];
        if (!result) {
            return;
        }
        closeList();
        input.value = '';
        results = [];
        load(urlFromExplore(result.explore), true).then(scrollToResults);
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        var query = input.value.trim();
        if (query.length < 2) {
            results = [];
            closeList();
            return;
        }
        timer = window.setTimeout(function () {
            suggest(query);
        }, 160);
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeList();
            return;
        }
        if (list.hidden || !results.length) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight((active + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight((active - 1 + results.length) % results.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(active >= 0 ? active : 0);
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(closeList, 120);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var query = input.value.trim();
        if (!query) {
            return;
        }
        if (results.length && shownFor === query) {
            choose(active >= 0 ? active : 0);
            return;
        }
        closeList();
        load(base + '?q=' + encodeURIComponent(query), true).then(scrollToResults);
    });
})();
