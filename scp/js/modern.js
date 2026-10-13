/*
 * osTicketCustom — behaviour for the modern agent theme
 *
 *  - colour scheme (light / dark / follow system) with per-agent override
 *  - density toggle for queues
 *  - collapsible long thread entries and "jump to latest"
 *  - click-to-copy for <code class="copyable">
 *  - live relative times
 */
(function ($) {
    'use strict';

    var Theme = {
        key: 'ost-theme',
        media: window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null,

        configured: function () {
            var m = document.body.className.match(/ost-theme-(light|dark|auto)/);
            return m ? m[1] : 'light';
        },
        preference: function () {
            try { return window.localStorage.getItem(this.key); } catch (e) { return null; }
        },
        resolve: function () {
            var pref = this.preference() || this.configured();
            if (pref === 'auto')
                return this.media && this.media.matches ? 'dark' : 'light';
            return pref;
        },
        apply: function () {
            var b = document.body, mode = this.resolve();
            b.classList.remove('ost-theme-light', 'ost-theme-dark');
            b.classList.add('ost-theme-' + mode);
            b.setAttribute('data-theme', mode);
            $('#theme-toggle').attr('data-mode', mode)
                .find('span').text(mode === 'dark' ? $('#theme-toggle').data('lightLabel') : $('#theme-toggle').data('darkLabel'));
            $('#theme-toggle i').attr('class', mode === 'dark' ? 'icon-sun' : 'icon-moon');
        },
        toggle: function () {
            var next = this.resolve() === 'dark' ? 'light' : 'dark';
            try { window.localStorage.setItem(this.key, next); } catch (e) {}
            this.apply();
        }
    };

    // Apply as early as possible to avoid a flash of the wrong scheme
    Theme.apply();
    if (Theme.media && Theme.media.addEventListener)
        Theme.media.addEventListener('change', function () { Theme.apply(); });

    $(document).on('click', '#theme-toggle', function (e) {
        e.preventDefault();
        Theme.toggle();
    });

    // Queue density
    var Density = {
        key: 'ost-density',
        apply: function () {
            var pref = null;
            try { pref = window.localStorage.getItem(this.key); } catch (e) {}
            if (!pref) return;
            document.body.classList.remove('ost-density-comfortable', 'ost-density-compact');
            document.body.classList.add('ost-density-' + pref);
            $('.queue-density-toggle a').removeClass('active').filter('[data-density="' + pref + '"]').addClass('active');
        }
    };
    Density.apply();
    $(document).on('click', '.queue-density-toggle a', function (e) {
        e.preventDefault();
        try { window.localStorage.setItem(Density.key, $(this).data('density')); } catch (err) {}
        Density.apply();
    });

    // Click to copy
    $(document).on('click', 'code.copyable', function () {
        var text = $(this).text(), $el = $(this);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                $el.attr('title', 'Copied');
            });
        }
    });

    // Thread: collapse very long entries, add jump-to-latest
    function prepThread() {
        $('.thread-entry .thread-body').each(function () {
            var $b = $(this);
            if ($b.data('prepped')) return;
            $b.data('prepped', true);
            if ($b.outerHeight() > 520 && !$b.closest('.thread-entry').hasClass('expanded')) {
                $b.addClass('collapsible');
                $('<a href="#" class="expand-entry"></a>')
                    .text($b.data('expandLabel') || 'Show full message')
                    .insertAfter($b)
                    .on('click', function (e) {
                        e.preventDefault();
                        $b.removeClass('collapsible').closest('.thread-entry').addClass('expanded');
                        $(this).remove();
                    });
            }
        });
        var $thread = $('#thread-items');
        if ($thread.length && !$('.thread-jump').length && $('.thread-entry').length > 3) {
            var $jump = $('<a href="#" class="thread-jump action-button"></a>')
                .html('<i class="icon-arrow-down"></i> ' + ($thread.data('jumpLabel') || 'Latest'))
                .appendTo('body')
                .on('click', function (e) {
                    e.preventDefault();
                    var $last = $('.thread-entry').last();
                    $('html, body').animate({ scrollTop: $last.offset().top - 80 }, 250);
                });
            $(window).on('scroll.threadjump', function () {
                var last = $('.thread-entry').last().offset();
                $jump.toggle(!!last && (window.scrollY + window.innerHeight) < last.top);
            }).trigger('scroll.threadjump');
        }
    }

    // Relative times refresh (every minute)
    function refreshRelative() {
        $('time.relative[datetime]').each(function () {
            var d = new Date($(this).attr('datetime')), diff = (Date.now() - d.getTime()) / 1000, txt;
            if (isNaN(diff)) return;
            if (diff < 60) txt = 'just now';
            else if (diff < 3600) txt = Math.floor(diff / 60) + 'm ago';
            else if (diff < 86400) txt = Math.floor(diff / 3600) + 'h ago';
            else if (diff < 86400 * 14) txt = Math.floor(diff / 86400) + 'd ago';
            else return;
            $(this).text(txt);
        });
    }

    function init() {
        prepThread();
        refreshRelative();
        $('[data-copy-url]').attr('title', 'Click to copy');
    }

    $(init);
    $(document).on('pjax:end', init);
    setInterval(refreshRelative, 60000);

    // Keyboard: "/" focuses the queue search, "g d" goes to the dashboard
    $(document).on('keydown', function (e) {
        if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
        if (e.key === '/') {
            var $s = $('#basic_search input.basic-search');
            if ($s.length) { e.preventDefault(); $s.focus(); }
        }
    });
})(jQuery);
