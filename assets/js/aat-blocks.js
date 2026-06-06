(function (blocks, blockEditor, components, element, i18n, serverSideRender) {
    'use strict';

    var el = element.createElement;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var SelectControl = components.SelectControl;
    var ToggleControl = components.ToggleControl;
    var RangeControl = components.RangeControl;
    var ServerSideRender = serverSideRender;

    function preview(name, props) {
        return el('div', { className: 'aat-block-preview' },
            el(ServerSideRender, {
                block: name,
                attributes: props.attributes
            })
        );
    }

    function textAttr(props, key, label, help) {
        return el(TextControl, {
            label: label,
            value: props.attributes[key] || '',
            help: help || '',
            onChange: function (value) {
                var next = {};
                next[key] = value;
                props.setAttributes(next);
            }
        });
    }

    function boolAttr(props, key, label) {
        return el(ToggleControl, {
            label: label,
            checked: !!props.attributes[key],
            onChange: function (value) {
                var next = {};
                next[key] = value;
                props.setAttributes(next);
            }
        });
    }

    blocks.registerBlockType('academy-awards/database', {
        title: __('Academy Awards Database', 'academy-awards-table'),
        icon: 'awards',
        category: 'lunara',
        attributes: {
            category: { type: 'string', default: '' },
            awardClass: { type: 'string', default: '' },
            year: { type: 'string', default: '' },
            ceremony: { type: 'string', default: '' },
            winnersOnly: { type: 'boolean', default: false },
            layout: { type: 'string', default: 'full' },
            autoload: { type: 'boolean', default: false },
            limit: { type: 'number', default: 0 }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Database Filters', 'academy-awards-table') },
                        textAttr(props, 'category', __('Category', 'academy-awards-table')),
                        textAttr(props, 'awardClass', __('Class', 'academy-awards-table')),
                        textAttr(props, 'year', __('Year', 'academy-awards-table'), __('Use latest for the newest year.', 'academy-awards-table')),
                        textAttr(props, 'ceremony', __('Ceremony', 'academy-awards-table'), __('Use latest for the newest ceremony.', 'academy-awards-table')),
                        boolAttr(props, 'winnersOnly', __('Winners only', 'academy-awards-table')),
                        el(SelectControl, {
                            label: __('Layout', 'academy-awards-table'),
                            value: props.attributes.layout,
                            options: [
                                { label: 'Full', value: 'full' },
                                { label: 'Embedded', value: 'embedded' }
                            ],
                            onChange: function (value) {
                                props.setAttributes({ layout: value });
                            }
                        }),
                        boolAttr(props, 'autoload', __('Load table immediately', 'academy-awards-table')),
                        el(RangeControl, {
                            label: __('Limit', 'academy-awards-table'),
                            value: props.attributes.limit,
                            min: 0,
                            max: 200,
                            onChange: function (value) {
                                props.setAttributes({ limit: value || 0 });
                            }
                        })
                    )
                ),
                preview('academy-awards/database', props)
            ];
        },
        save: function () {
            return null;
        }
    });

    blocks.registerBlockType('academy-awards/tracker', {
        title: __('Lunara Awards Tracker', 'academy-awards-table'),
        icon: 'chart-bar',
        category: 'lunara',
        attributes: {
            ceremony: { type: 'string', default: 'latest' },
            year: { type: 'string', default: '' },
            category: { type: 'string', default: '' },
            awardClass: { type: 'string', default: '' },
            winnersOnly: { type: 'boolean', default: false },
            layout: { type: 'string', default: 'embedded' }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Tracker', 'academy-awards-table') },
                        textAttr(props, 'ceremony', __('Ceremony', 'academy-awards-table')),
                        textAttr(props, 'year', __('Year', 'academy-awards-table')),
                        textAttr(props, 'category', __('Category', 'academy-awards-table')),
                        textAttr(props, 'awardClass', __('Class', 'academy-awards-table')),
                        boolAttr(props, 'winnersOnly', __('Winners only', 'academy-awards-table'))
                    )
                ),
                preview('academy-awards/tracker', props)
            ];
        },
        save: function () {
            return null;
        }
    });

    blocks.registerBlockType('academy-awards/tracker-v2', {
        title: __('Lunara Awards Tracker V2', 'academy-awards-table'),
        icon: 'star-filled',
        category: 'lunara',
        attributes: {
            ceremony: { type: 'string', default: 'latest' },
            showSelector: { type: 'boolean', default: true },
            showPosters: { type: 'boolean', default: true },
            showImdb: { type: 'boolean', default: true },
            showReviewLinks: { type: 'boolean', default: true }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Tracker V2', 'academy-awards-table') },
                        textAttr(props, 'ceremony', __('Ceremony', 'academy-awards-table')),
                        boolAttr(props, 'showSelector', __('Show selector', 'academy-awards-table')),
                        boolAttr(props, 'showPosters', __('Show posters', 'academy-awards-table')),
                        boolAttr(props, 'showImdb', __('Show IMDb links', 'academy-awards-table')),
                        boolAttr(props, 'showReviewLinks', __('Show review links', 'academy-awards-table'))
                    )
                ),
                preview('academy-awards/tracker-v2', props)
            ];
        },
        save: function () {
            return null;
        }
    });

    blocks.registerBlockType('academy-awards/ballot', {
        title: __('Oscar Ballot', 'academy-awards-table'),
        icon: 'yes-alt',
        category: 'lunara',
        attributes: {
            ceremony: { type: 'string', default: 'latest' },
            headline: { type: 'string', default: '' },
            intro: { type: 'string', default: '' },
            showSelector: { type: 'boolean', default: true },
            categories: { type: 'string', default: '' }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Ballot', 'academy-awards-table') },
                        textAttr(props, 'ceremony', __('Ceremony', 'academy-awards-table')),
                        textAttr(props, 'headline', __('Headline', 'academy-awards-table')),
                        el(TextareaControl, {
                            label: __('Intro', 'academy-awards-table'),
                            value: props.attributes.intro,
                            onChange: function (value) {
                                props.setAttributes({ intro: value });
                            }
                        }),
                        boolAttr(props, 'showSelector', __('Show selector', 'academy-awards-table')),
                        textAttr(props, 'categories', __('Categories', 'academy-awards-table'), __('Comma-separated category filter.', 'academy-awards-table'))
                    )
                ),
                preview('academy-awards/ballot', props)
            ];
        },
        save: function () {
            return null;
        }
    });
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender);
