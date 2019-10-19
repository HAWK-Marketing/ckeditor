import Plugin from '../ckeditor/node_modules/@ckeditor/ckeditor5-core/src/plugin';
import imageIcon from '../ckeditor/node_modules/@ckeditor/ckeditor5-core/theme/icons/image.svg';
import linkIcon from './backlinks.svg';
import ButtonView from '../ckeditor/node_modules/@ckeditor/ckeditor5-ui/src/button/buttonview';

export default class CraftCMS extends Plugin {
    init() {
        console.log('CraftCMS plugin!');

        const editor = this.editor;

        editor.ui.componentFactory.add( 'insertImage', locale => {
            const view = new ButtonView( locale );

            view.set( {
                label: 'Insert image',
                icon: imageIcon,
                tooltip: true
            } );

            // Callback executed once the image is clicked.
            view.on( 'execute', () => {
                Craft.createElementSelectorModal('craft\\elements\\Asset', {
                    storageKey: 'CKEditorInput.ChooseImage',
                    multiSelect: true,
                    sources: editor.config.get( 'craftcms.volumes' ),
                    criteria: {siteId: editor.config.get( 'craftcms.siteId' ), kind: 'image'},
                    onSelect: $.proxy(function(assets, transform) {
                        if (assets.length) {
                            // Loop over each asset creating document fragment
                            editor.model.change( writer => {
                                const docFrag = writer.createDocumentFragment();

                                for (let i = 0; i < assets.length; i++) {
                                    let asset = assets[i],
                                        url = asset.url + '#asset:' + asset.id;

                                    if (transform) {
                                        url += ':transform:' + transform;
                                    }

                                    writer.append( writer.createElement('image', {
                                        src: url
                                    }), docFrag );
                                }

                                // Add all document fragments
                                editor.model.insertContent(docFrag, editor.model.document.selection);
                            } );
                        }
                    }, this),
                    closeOtherModals: false,
                    transforms: editor.config.get( 'craftcms.transforms' )
                });
            } );

            return view;
        } );

        editor.ui.componentFactory.add( 'insertInternalLink', locale => {
            const view = new ButtonView( locale );

            view.set( {
                label: 'Internal Link',
                icon: linkIcon,
                tooltip: true
            } );

            // Callback executed once the image is clicked.
            view.on( 'execute', () => {
                const linkCommand = editor.commands.get( 'link' );

                Craft.createElementSelectorModal('craft\\elements\\Entry', {
                    storageKey: 'CKEditorInput.LinkTo.Entry',
                    criteria: {siteId: editor.config.get( 'craftcms.siteId' )},
                    onSelect: $.proxy(function(elements) {
                        if (elements.length) {
                            var element = elements[0];

                            linkCommand.execute( element.url, {
                                linkIsExternal: false
                            } );
                        }
                    }, this),
                    closeOtherModals: false,
                });
            } );

            return view;
        } );
    }
}