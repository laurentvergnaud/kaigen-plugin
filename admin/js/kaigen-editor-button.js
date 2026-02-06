jQuery(document).ready(function($) {
    'use strict';

    // Add button to Gutenberg editor
    if (typeof wp !== 'undefined' && wp.data && wp.plugins && wp.editPost) {
        var el = wp.element.createElement;
        var Fragment = wp.element.Fragment;
        var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
        var registerPlugin = wp.plugins.registerPlugin;
        var Button = wp.components.Button;

        var KaigenEditorPanel = function() {
            return el(
                PluginDocumentSettingPanel,
                {
                    name: 'kaigen-editor-panel',
                    title: 'Kaigen',
                    className: 'kaigen-editor-panel'
                },
                el(
                    Fragment,
                    {},
                    el(
                        'p',
                        {},
                        'Edit this post in Kaigen for AI-powered content generation and optimization.'
                    ),
                    el(
                        Button,
                        {
                            isPrimary: true,
                            isLarge: true,
                            href: kaigenEditor.editorUrl,
                            target: '_blank',
                            rel: 'noopener noreferrer'
                        },
                        kaigenEditor.strings.openInKaigen
                    )
                )
            );
        };

        registerPlugin('kaigen-editor-panel', {
            render: KaigenEditorPanel,
            icon: 'admin-customizer'
        });
    }
});





