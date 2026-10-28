(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var searchForm = document.querySelector('.sf-search');
        var input = document.getElementById('sf-search-input');
        var panel = document.getElementById('sf-search-suggest');

        if (searchForm && input && panel && searchForm.dataset.suggestUrl) {
            var suggestUrl = searchForm.dataset.suggestUrl;
            var timer = null;
            var controller = null;
            var activeIndex = -1;

            function links() {
                return Array.prototype.slice.call(panel.querySelectorAll('a'));
            }

            function hide() {
                panel.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                activeIndex = -1;
            }

            function setActive(index) {
                var items = links();
                if (!items.length) return;
                activeIndex = (index + items.length) % items.length;
                items.forEach(function (item, i) {
                    if (i === activeIndex) {
                        item.classList.add('is-active');
                    } else {
                        item.classList.remove('is-active');
                    }
                });
                input.setAttribute('aria-activedescendant', items[activeIndex].id);
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            }

            function render(items, query) {
                panel.innerHTML = '';
                if (!items.length) {
                    var empty = document.createElement('div');
                    empty.className = 'sf-suggest-empty';
                    empty.textContent = 'No products match "' + query + '".';
                    panel.appendChild(empty);
                } else {
                    items.forEach(function (item) {
                        var link = document.createElement('a');
                        link.href = item.url;
                        link.setAttribute('role', 'option');
                        link.id = 'sf-suggest-option-' + items.indexOf(item);

                        if (item.image) {
                            var img = document.createElement('img');
                            img.src = item.image;
                            img.alt = '';
                            img.loading = 'lazy';
                            link.appendChild(img);
                        } else {
                            var blank = document.createElement('span');
                            blank.className = 'sf-suggest-noimg';
                            blank.setAttribute('aria-hidden', 'true');
                            link.appendChild(blank);
                        }

                        var text = document.createElement('span');
                        var name = document.createElement('strong');
                        name.textContent = item.name;
                        var meta = document.createElement('small');
                        meta.textContent = item.meta || '';
                        text.appendChild(name);
                        text.appendChild(meta);
                        link.appendChild(text);

                        var price = document.createElement('span');
                        price.className = 'sf-suggest-price';
                        price.textContent = item.price;
                        link.appendChild(price);

                        link.addEventListener('mouseenter', function () {
                            setActive(links().indexOf(link));
                        });
                        panel.appendChild(link);
                    });
                }
                panel.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }

            function fetchSuggestions() {
                var query = input.value.trim();
                if (query.length < 2) {
                    hide();
                    return;
                }
                if (controller) controller.abort();
                controller = new AbortController();
                fetch(suggestUrl + '?q=' + encodeURIComponent(query), {
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal
                }).then(function (response) {
                    if (!response.ok) throw new Error('Search failed');
                    return response.json();
                }).then(function (data) {
                    if (input.value.trim() !== query) return;
                    render(Array.isArray(data.items) ? data.items : [], query);
                }).catch(function (error) {
                    if (error.name !== 'AbortError') hide();
                });
            }

            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(fetchSuggestions, 180);
            });

            input.addEventListener('keydown', function (event) {
                var items = links();
                if (event.key === 'Escape') {
                    hide();
                    return;
                }
                if (panel.hidden || !items.length) return;
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActive(activeIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActive(activeIndex - 1);
                } else if (event.key === 'Enter' && activeIndex >= 0) {
                    event.preventDefault();
                    items[activeIndex].click();
                }
            });

            document.addEventListener('click', function (event) {
                if (!panel.contains(event.target) && event.target !== input) hide();
            });

            input.addEventListener('focus', function () {
                if (input.value.trim().length >= 2 && panel.innerHTML !== '') {
                    panel.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }
            });
        }

        var filterButton = document.querySelector('[data-sf-open-filters]');
        var filters = document.querySelector('.sf-filters');
        if (filterButton && filters) {
            function syncFilterState() {
                filterButton.setAttribute('aria-expanded', filters.offsetParent !== null ? 'true' : 'false');
            }

            syncFilterState();
            window.addEventListener('resize', syncFilterState);
            filterButton.addEventListener('click', function (event) {
                event.preventDefault();
                filters.classList.toggle('is-hidden-mobile');
                if (!filters.classList.contains('is-hidden-mobile')) {
                    var first = filters.querySelector('select, input');
                    if (first) first.focus();
                }
                syncFilterState();
            });
        }

        var galleryMain = document.getElementById('sf-gallery-main');
        var thumbnails = document.querySelectorAll('[data-sf-gallery-thumb]');
        if (galleryMain && thumbnails.length) {
            thumbnails.forEach(function (thumb) {
                thumb.addEventListener('click', function () {
                    var image = thumb.getAttribute('data-sf-gallery-thumb');
                    if (!image) return;
                    galleryMain.src = image;
                    thumbnails.forEach(function (item) {
                        item.setAttribute('aria-current', item === thumb ? 'true' : 'false');
                    });
                });
            });
        }
    });
})();
