(function () {
    'use strict';

    var reviewedAttr = 'data-freego-wp-runtime-checked';
    var config = window.FreegoWP || {};
    var aggressiveRepair = !!config.aggressiveRepair;
    var fallbacks = config.fallbacks || {};
    var repairCandidateSelector = 'img, [role="img"]:not(svg), a[href], button, iframe, input, textarea, select, table, th, object, embed, applet, h1, h2, h3, h4, h5, h6, [aria-expanded]';
    var observedDocuments = [];

    function hasText(value) {
        return typeof value === 'string' && value.trim().length > 0;
    }

    function isHidden(element) {
        if (!element || element.getAttribute('aria-hidden') === 'true') {
            return true;
        }

        var ownerWindow = element.ownerDocument.defaultView || window;
        var style = ownerWindow.getComputedStyle(element);
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

    function labelFromClasses(className) {
        className = String(className || '').toLowerCase();

        if (/\b(close|dismiss)\b|closeicon/.test(className)) {
            return 'close';
        }

        if (/\b(menu|submenu|toggle|expand)\b/.test(className)) {
            return 'menu';
        }

        if (/\bsearch\b/.test(className)) {
            return 'search';
        }

        return '';
    }

    function labelFromLinkContext(link) {
        var href = String(link.getAttribute('href') || '').toLowerCase();
        var service = serviceLabelFromHref(href);
        if (hasText(service)) {
            if (/(^|[/?&=._-])(share|sharer|sharearticle|submit|pin|send)($|[/?&=._-])/.test(href)) {
                return formatServiceLabel(fallbacks.shareOn || 'Share on %s', service);
            }

            if (/(^|[/?&=._-])(save|bookmark)($|[/?&=._-])/.test(href)) {
                return formatServiceLabel(fallbacks.saveTo || 'Save to %s', service);
            }
        }

        return semanticTokenFromClasses(link);
    }

    function formatServiceLabel(template, service) {
        return String(template || '').indexOf('%s') !== -1
            ? String(template).replace('%s', service)
            : String(template || '') + ' ' + service;
    }

    function serviceLabelFromHref(href) {
        if (href.indexOf('whatsapp:') === 0) {
            return 'WhatsApp';
        }

        var host = '';
        try {
            host = new URL(href, window.location.href).hostname.toLowerCase().replace(/^www\./, '');
        } catch (error) {
            return '';
        }

        var labels = {
            'x.com': 'X',
            'twitter.com': 'Twitter',
            'facebook.com': 'Facebook',
            'pinterest.com': 'Pinterest',
            'linkedin.com': 'LinkedIn',
            'tumblr.com': 'Tumblr',
            'reddit.com': 'Reddit',
            'getpocket.com': 'Pocket',
            'vk.com': 'VKontakte',
            'ok.ru': 'OK',
            'connect.ok.ru': 'OK'
        };

        for (var domain in labels) {
            if (Object.prototype.hasOwnProperty.call(labels, domain) && (host === domain || host.slice(-(domain.length + 1)) === '.' + domain)) {
                return labels[domain];
            }
        }

        return host.split('.')[0].replace(/[-_]+/g, ' ').replace(/\b\w/g, function (letter) {
            return letter.toUpperCase();
        });
    }

    function semanticTokenFromClasses(element) {
        var ignored = {
            a: true,
            link: true,
            links: true,
            icon: true,
            icons: true,
            social: true,
            share: true,
            sharing: true,
            button: true,
            btn: true,
            tfm: true,
            cmswt: true
        };
        var current = element;

        while (current && current.nodeType === 1) {
            var classes = String(current.className || '').toLowerCase().split(/\s+/);
            for (var i = 0; i < classes.length; i++) {
                var parts = classes[i].split(/[-_]+/);
                for (var j = 0; j < parts.length; j++) {
                    var token = parts[j].trim();
                    if (/^[a-z][a-z0-9]{1,24}$/.test(token) && !ignored[token]) {
                        return token.replace(/\b\w/g, function (letter) {
                            return letter.toUpperCase();
                        });
                    }
                }
            }
            current = current.parentElement;
        }

        return '';
    }

    function hasAccessibleName(element) {
        if (hasText(element.getAttribute('aria-label')) || hasText(element.getAttribute('title')) || hasText(element.getAttribute('alt'))) {
            return true;
        }

        var labelledby = element.getAttribute('aria-labelledby');
        if (hasText(labelledby)) {
            return labelledby.split(/\s+/).some(function (id) {
                var label = element.ownerDocument.getElementById(id);
                return label && hasText(label.textContent);
            });
        }

        if (hasText(element.id)) {
            var ownerWindow = element.ownerDocument.defaultView || window;
            var escapeCss = ownerWindow.CSS && typeof ownerWindow.CSS.escape === 'function'
                ? ownerWindow.CSS.escape
                : function (value) {
                    return String(value).replace(/["\\]/g, '\\$&');
                };
            var explicit = element.ownerDocument.querySelector('label[for="' + escapeCss(element.id) + '"]');
            if (explicit && hasText(explicit.textContent)) {
                return true;
            }
        }

        return false;
    }

    function repairImages(root) {
        scopedElements(root, 'img:not([' + reviewedAttr + '])').forEach(function (image) {
            image.setAttribute(reviewedAttr, '1');

            if (!image.hasAttribute('alt')) {
                image.setAttribute('alt', aggressiveRepair ? (fallbacks.image || 'image') : '');
                image.setAttribute('data-freego-wp-needs-alt-review', '1');
                return;
            }

            if (isHidden(image)) {
                return;
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

            var visibleText = link.textContent.replace(/\s+/g, ' ').trim();
            var text = visibleText;
            var image = link.querySelector('img[alt]');
            if (!hasText(text) && image) {
                text = image.getAttribute('alt').trim();
            }

            if (hasText(text) && !hasText(link.getAttribute('title'))) {
                link.setAttribute('title', text);
            }

            if (hasText(visibleText)) {
                link.querySelectorAll('img[alt]').forEach(function (imageNode) {
                    if (!isHidden(imageNode) && imageNode.getAttribute('alt').trim() === visibleText) {
                        imageNode.setAttribute('alt', '');
                        imageNode.setAttribute('data-freego-wp-needs-alt-review', '1');
                    }
                });
            }

            if (!hasText(text) && hasText(link.getAttribute('title'))) {
                link.setAttribute('aria-label', link.getAttribute('title').trim());
                link.setAttribute('data-freego-wp-needs-link-review', '1');
                return;
            }

            if (!hasText(text) && !hasAccessibleName(link)) {
                var label = labelFromLinkContext(link);
                if (hasText(label)) {
                    link.setAttribute('aria-label', label);
                    link.setAttribute('title', label);
                    return;
                }

                if (aggressiveRepair) {
                    label = labelFromUrl(link.getAttribute('href'));
                    link.setAttribute('aria-label', label);
                    link.setAttribute('title', label);
                }
                link.setAttribute('data-freego-wp-needs-link-review', '1');
            }
        });
    }

    function repairButtons(root) {
        scopedElements(root, 'button:not([' + reviewedAttr + '])').forEach(function (button) {
            button.setAttribute(reviewedAttr, '1');
            if (isHidden(button) || hasText(button.textContent) || hasAccessibleName(button)) {
                return;
            }

            var label = labelFromClasses(button.className);
            if (!hasText(label) && button.parentElement) {
                var text = button.parentElement.textContent.replace(/\s+/g, ' ').trim();
                if (text.length > 0 && text.length <= 80) {
                    label = text;
                }
            }

            if (!hasText(label)) {
                if (!aggressiveRepair) {
                    button.setAttribute('data-freego-wp-needs-name-review', '1');
                    return;
                }
                label = fallbacks.button || 'button';
            }

            button.setAttribute('aria-label', label);
            button.setAttribute('data-freego-wp-needs-name-review', '1');
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
            observeIframe(iframe);
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

            var label = control.ownerDocument.createElement('label');
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

        var group = select.ownerDocument.createElement('optgroup');
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

            var target = toggle.ownerDocument.getElementById(toggle.getAttribute('aria-controls'));
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
        repairButtons(root);
        repairIframes(root);
        repairControls(root);
        repairTables(root);
        repairEmbeds(root);
        markHeadingReview(root);
        repairDisclosure(root);
    }

    function sameOriginIframeDocument(iframe) {
        try {
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc || !doc.documentElement) {
                return null;
            }

            return doc;
        } catch (error) {
            iframe.setAttribute('data-freego-wp-cross-origin-frame', '1');
            return null;
        }
    }

    function observeIframe(iframe) {
        if (!iframe.hasAttribute('data-freego-wp-frame-listener')) {
            iframe.setAttribute('data-freego-wp-frame-listener', '1');
            iframe.addEventListener('load', function () {
                iframe.removeAttribute(reviewedAttr);
                observeIframe(iframe);
                queueRun(iframe);
            });
        }

        var doc = sameOriginIframeDocument(iframe);
        if (!doc) {
            return;
        }

        run(doc);
        observeDocument(doc);
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

    function observeDocument(doc) {
        if (!('MutationObserver' in window) || observedDocuments.indexOf(doc) !== -1 || !doc.documentElement) {
            return;
        }

        observedDocuments.push(doc);
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
        }).observe(doc.documentElement, {
            attributeFilter: ['alt', 'aria-controls', 'aria-expanded', 'aria-hidden', 'aria-label', 'aria-labelledby', 'class', 'headers', 'href', 'id', 'name', 'placeholder', 'role', 'scope', 'src', 'style', 'title', 'type'],
            attributes: true,
            characterData: true,
            childList: true,
            subtree: true
        });
    }

    observeDocument(document);
})();
