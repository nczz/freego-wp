(function () {
    'use strict';

    var reviewedAttr = 'data-freego-wp-runtime-checked';
    var config = window.FreegoWP || {};
    var aggressiveRepair = !!config.aggressiveRepair;
    var fallbacks = config.fallbacks || {};
    var repairCandidateSelector = 'img, [role="img"]:not(svg), a[href], iframe, input, textarea, select, table, th, object, embed, applet, h1, h2, h3, h4, h5, h6, [aria-expanded]';

    function hasText(value) {
        return typeof value === 'string' && value.trim().length > 0;
    }

    function isHidden(element) {
        if (!element || element.getAttribute('aria-hidden') === 'true') {
            return true;
        }

        var style = window.getComputedStyle(element);
        return style.display === 'none' || style.visibility === 'hidden';
    }

    function scopedElements(root, selector) {
        var elements = [];
        if (!root || root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) {
            return elements;
        }

        if (root.nodeType === Node.ELEMENT_NODE && root.matches(selector)) {
            elements.push(root);
        }

        return elements.concat(Array.prototype.slice.call(root.querySelectorAll(selector)));
    }

    function labelFromUrl(url) {
        if (!hasText(url)) {
                return fallbacks.link || 'link';
        }

        try {
            var parsed = new URL(url, window.location.href);
            var segment = parsed.pathname.split('/').filter(Boolean).pop();
            if (segment) {
                return decodeURIComponent(segment).replace(/[-_]+/g, ' ');
            }
        } catch (error) {
            return 'Link pending accessibility review';
        }

        return fallbacks.link || 'link';
    }

    function hasAccessibleName(element) {
        if (hasText(element.getAttribute('aria-label')) || hasText(element.getAttribute('title')) || hasText(element.getAttribute('alt'))) {
            return true;
        }

        var labelledby = element.getAttribute('aria-labelledby');
        if (hasText(labelledby)) {
            return labelledby.split(/\s+/).some(function (id) {
                var label = document.getElementById(id);
                return label && hasText(label.textContent);
            });
        }

        if (hasText(element.id)) {
            var escapeCss = window.CSS && typeof window.CSS.escape === 'function'
                ? window.CSS.escape
                : function (value) {
                    return String(value).replace(/["\\]/g, '\\$&');
                };
            var explicit = document.querySelector('label[for="' + escapeCss(element.id) + '"]');
            if (explicit && hasText(explicit.textContent)) {
                return true;
            }
        }

        return false;
    }

    function repairImages(root) {
        scopedElements(root, 'img:not([' + reviewedAttr + '])').forEach(function (image) {
            image.setAttribute(reviewedAttr, '1');
            if (isHidden(image)) {
                return;
            }

            if (!image.hasAttribute('alt')) {
                image.setAttribute('alt', aggressiveRepair ? (fallbacks.image || 'image') : '');
                image.setAttribute('data-freego-wp-needs-alt-review', '1');
            } else if (aggressiveRepair && image.getAttribute('alt').trim() === '' && !image.hasAttribute('title')) {
                image.setAttribute('alt', fallbacks.image || 'image');
                image.setAttribute('data-freego-wp-needs-alt-review', '1');
            } else if (image.getAttribute('alt').trim() === '' && image.hasAttribute('title')) {
                if (aggressiveRepair) {
                    image.setAttribute('alt', image.getAttribute('title').trim() || (fallbacks.image || 'image'));
                } else {
                    image.removeAttribute('title');
                }
                image.setAttribute('data-freego-wp-needs-alt-review', '1');
            } else if (image.hasAttribute('src') && image.getAttribute('src').trim() === image.getAttribute('alt').trim()) {
                image.setAttribute('data-freego-wp-needs-alt-review', '1');
            }
        });

        scopedElements(root, '[role="img"]:not(img):not(svg):not([' + reviewedAttr + '])').forEach(function (node) {
            node.setAttribute(reviewedAttr, '1');
            if (!isHidden(node) && !hasAccessibleName(node)) {
                if (aggressiveRepair) {
                    node.setAttribute('aria-label', fallbacks.image || 'image');
                }
                node.setAttribute('data-freego-wp-needs-name-review', '1');
            }
        });
    }

    function repairLinks(root) {
        scopedElements(root, 'a[href]:not([' + reviewedAttr + '])').forEach(function (link) {
            link.setAttribute(reviewedAttr, '1');
            if (isHidden(link)) {
                return;
            }

            var text = link.textContent.replace(/\s+/g, ' ').trim();
            var image = link.querySelector('img[alt]');
            if (!hasText(text) && image) {
                text = image.getAttribute('alt').trim();
            }

            if (hasText(text) && !hasText(link.getAttribute('title'))) {
                link.setAttribute('title', text);
            }

            if (!hasText(text) && !hasAccessibleName(link)) {
                if (aggressiveRepair) {
                    var label = labelFromUrl(link.getAttribute('href'));
                    link.setAttribute('aria-label', label);
                    link.setAttribute('title', label);
                }
                link.setAttribute('data-freego-wp-needs-link-review', '1');
            }
        });
    }

    function repairIframes(root) {
        scopedElements(root, 'iframe:not([' + reviewedAttr + '])').forEach(function (iframe) {
            iframe.setAttribute(reviewedAttr, '1');
            if (!hasText(iframe.getAttribute('title'))) {
                if (aggressiveRepair) {
                    iframe.setAttribute('title', labelFromUrl(iframe.getAttribute('src')) || (fallbacks.frame || 'frame'));
                }
                iframe.setAttribute('data-freego-wp-needs-title-review', '1');
            }
        });
    }

    function repairControls(root) {
        scopedElements(root, 'input, textarea, select').forEach(function (control) {
            if (control.hasAttribute(reviewedAttr) || isHidden(control)) {
                return;
            }

            control.setAttribute(reviewedAttr, '1');
            var type = (control.getAttribute('type') || '').toLowerCase();
            if (['hidden', 'submit', 'button'].indexOf(type) !== -1) {
                return;
            }

            if (type === 'image' && !hasAccessibleName(control)) {
                if (aggressiveRepair) {
                    control.setAttribute('alt', fallbacks.submit || 'submit');
                }
                control.setAttribute('data-freego-wp-needs-name-review', '1');
                return;
            }

            if (hasAccessibleName(control)) {
                return;
            }

            if (!hasText(control.id)) {
                control.id = 'freego-wp-field-' + Math.random().toString(16).slice(2);
            }

            var label = document.createElement('label');
            label.className = 'freego-wp-sr-only';
            label.setAttribute('for', control.id);
            label.setAttribute('data-freego-wp-needs-label-review', '1');
            label.textContent = control.getAttribute('placeholder') || control.getAttribute('name') || 'Field pending accessibility review';
            control.parentNode.insertBefore(label, control);
        });
    }

    function repairTables(root) {
        scopedElements(root, 'table:not([' + reviewedAttr + '])').forEach(function (table) {
            table.setAttribute(reviewedAttr, '1');
            if (!table.querySelector('caption')) {
                table.setAttribute('data-freego-wp-needs-caption-review', '1');
            }
        });

        scopedElements(root, 'th:not([scope])').forEach(function (header) {
            if (aggressiveRepair) {
                header.setAttribute('scope', inferThScope(header));
            }
            header.setAttribute('data-freego-wp-needs-table-review', '1');
        });

        if (aggressiveRepair) {
            scopedElements(root, 'table').forEach(repairTableHeaders);
        }
    }

    function repairEmbeds(root) {
        scopedElements(root, 'object, embed, applet').forEach(function (embed) {
            if (embed.hasAttribute(reviewedAttr) || isHidden(embed)) {
                return;
            }

            embed.setAttribute(reviewedAttr, '1');
            if (!hasText(embed.textContent) && !hasAccessibleName(embed)) {
                if (aggressiveRepair) {
                    embed.setAttribute('title', fallbacks.embed || 'embedded content');
                }
                embed.setAttribute('data-freego-wp-needs-embed-review', '1');
            }
        });

        scopedElements(root, 'select').forEach(function (select) {
            if (select.querySelectorAll('option').length > 8 && !select.querySelector('optgroup')) {
                if (aggressiveRepair) {
                    wrapOptionsInOptgroup(select);
                }
                select.setAttribute('data-freego-wp-needs-optgroup-review', '1');
            }
        });
    }

    function inferThScope(header) {
        var row = header.parentElement;
        if (!row) {
            return 'col';
        }

        var cells = Array.prototype.filter.call(row.children, function (child) {
            return ['TH', 'TD'].indexOf(child.tagName) !== -1;
        });

        return cells[0] === header ? 'row' : 'col';
    }

    function repairTableHeaders(table) {
        var headers = Array.prototype.slice.call(table.querySelectorAll('th'));
        if (!headers.length || !table.querySelector('td')) {
            return;
        }

        var ids = headers.slice(0, 6).map(function (header, index) {
            if (!hasText(header.id)) {
                header.id = 'freego-wp-th-' + index + '-' + Math.random().toString(16).slice(2);
            }
            return header.id;
        });

        table.querySelectorAll('td:not([headers])').forEach(function (cell) {
            cell.setAttribute('headers', ids.join(' '));
            cell.setAttribute('data-freego-wp-needs-table-review', '1');
        });
    }

    function wrapOptionsInOptgroup(select) {
        if (select.querySelector('optgroup')) {
            return;
        }

        var options = Array.prototype.slice.call(select.children).filter(function (child) {
            return child.tagName === 'OPTION';
        });

        if (!options.length) {
            return;
        }

        var group = document.createElement('optgroup');
        group.setAttribute('label', fallbacks.options || 'options');
        options.forEach(function (option) {
            group.appendChild(option);
        });
        select.appendChild(group);
    }

    function markHeadingReview(root) {
        var headings = scopedElements(root, 'h1, h2, h3, h4, h5, h6');
        var previous = 0;
        headings.forEach(function (heading) {
            var level = parseInt(heading.tagName.substring(1), 10);
            if (previous && level > previous + 1) {
                heading.setAttribute('data-freego-wp-needs-heading-review', '1');
            }
            previous = level;
        });
    }

    function repairDisclosure(root) {
        scopedElements(root, '[aria-expanded]').forEach(function (toggle) {
            if (!hasText(toggle.getAttribute('aria-controls'))) {
                return;
            }

            var target = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!target) {
                return;
            }

            target.hidden = toggle.getAttribute('aria-expanded') === 'false';
        });
    }

    function run(root) {
        root = root || document;
        repairImages(root);
        repairLinks(root);
        repairIframes(root);
        repairControls(root);
        repairTables(root);
        repairEmbeds(root);
        markHeadingReview(root);
        repairDisclosure(root);
    }

    var queuedRoots = [];
    var queued = false;

    function queueRun(root) {
        if (!root || root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) {
            return;
        }

        if (queuedRoots.indexOf(root) === -1) {
            queuedRoots.push(root);
        }

        if (queued) {
            return;
        }

        queued = true;
        (window.requestAnimationFrame || window.setTimeout)(function () {
            var roots = queuedRoots.slice();
            queuedRoots = [];
            queued = false;
            roots.forEach(run);
        });
    }

    function invalidate(element) {
        if (!element || element.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        var target = element.closest(repairCandidateSelector) || element;
        target.removeAttribute(reviewedAttr);
        queueRun(target);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            run(document);
        });
    } else {
        run(document);
    }

    if ('MutationObserver' in window) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            queueRun(node);
                        } else if (node.parentElement) {
                            invalidate(node.parentElement);
                        }
                    });
                } else if (mutation.type === 'attributes') {
                    invalidate(mutation.target);
                } else if (mutation.type === 'characterData' && mutation.target.parentElement) {
                    invalidate(mutation.target.parentElement);
                }
            });
        }).observe(document.documentElement, {
            attributeFilter: ['alt', 'aria-controls', 'aria-expanded', 'aria-hidden', 'aria-label', 'aria-labelledby', 'class', 'headers', 'href', 'id', 'name', 'placeholder', 'role', 'scope', 'src', 'style', 'title', 'type'],
            attributes: true,
            characterData: true,
            childList: true,
            subtree: true
        });
    }
})();
