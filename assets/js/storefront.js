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
        // Category cards rotate through product images when a category has more
        // than one product photo.
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!reduceMotion) {
            document.querySelectorAll('[data-sf-category-slides]').forEach(function (tile) {
                var slides = Array.prototype.slice.call(tile.querySelectorAll('img.sf-category-slide'));
                if (slides.length < 2) return;
                var index = 0;
                window.setInterval(function () {
                    slides[index].classList.remove('is-active');
                    index = (index + 1) % slides.length;
                    slides[index].classList.add('is-active');
                }, 3800);
            });
        }
    });

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

        var modalAddForm = document.querySelector('#sf-product-modal .sf-add-form');
        if (modalAddForm && window.fetch && window.FormData) {
            var feedback = document.createElement('p');
            feedback.className = 'sf-modal-cart-feedback';
            feedback.setAttribute('role', 'status');
            feedback.setAttribute('aria-live', 'polite');
            feedback.hidden = true;
            modalAddForm.parentNode.insertBefore(feedback, modalAddForm.nextSibling);

            var updateCartCount = function (count) {
                var link = document.querySelector('.sf-cart-link');
                if (!link) return;
                count = Math.max(0, parseInt(count, 10) || 0);
                var badge = link.querySelector('.sf-cart-count');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('strong');
                        badge.className = 'sf-cart-count';
                        link.appendChild(badge);
                    }
                    badge.textContent = count;
                } else if (badge) {
                    badge.remove();
                }
                link.setAttribute('aria-label', 'Cart, ' + count + ' item' + (count === 1 ? '' : 's'));
            };

            var showFeedback = function (message, type) {
                feedback.textContent = message;
                feedback.className = 'sf-modal-cart-feedback is-' + (type || 'success');
                feedback.hidden = false;
            };

            modalAddForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var form = this;
                var button = form.querySelector('button[type="submit"]');
                if (form.getAttribute('data-sf-submitting') === '1') return;
                form.setAttribute('data-sf-submitting', '1');
                var wasDisabled = button ? button.disabled : false;
                if (button) button.disabled = true;
                feedback.hidden = true;

                fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().catch(function () {
                        throw new Error('The cart could not be updated. Please try again.');
                    }).then(function (data) {
                        if (!response.ok) {
                            throw new Error(data && data.message ? data.message : 'The cart could not be updated.');
                        }
                        return data;
                    });
                }).then(function (data) {
                    if (typeof data.cart_count !== 'undefined') updateCartCount(data.cart_count);
                    showFeedback(data.message || 'Cart updated.', data.type);
                }).catch(function (error) {
                    showFeedback(error.message || 'The cart could not be updated. Please try again.', 'danger');
                }).then(function () {
                    form.setAttribute('data-sf-submitting', '0');
                    if (button) button.disabled = wasDisabled;
                });
            });
        }
    });
})();
