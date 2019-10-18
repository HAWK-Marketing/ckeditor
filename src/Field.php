<?php

namespace craft\ckeditor;

use Craft;
use craft\base\ElementInterface;
use craft\ckeditor\assets\field\FieldAsset;
use craft\helpers\FileHelper;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use yii\db\Schema;

/**
 * CKEditor field type
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Field extends \craft\base\Field
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('ckeditor', 'CKEditor');
    }

    // Properties
    // =========================================================================

    /**
     * @var string|null The HTML Purifier config file to use
     */
    public $purifierConfig;

    /**
     * @var string|null The CKEditor config file to use
     */
    public $ckeditorConfig;

    /**
     * @var bool Whether the HTML should be purified on save
     */
    public $purifyHtml = true;

    /**
     * @var string The type of database column the field should have in the content table
     */
    public $columnType = Schema::TYPE_TEXT;

    /**
     * @var string|array|null The volumes that should be available for Image selection.
     */
    public $availableVolumes = '*';

    /**
     * @var string|array|null The transforms available when selecting an image
     */
    public $availableTransforms = '*';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        // normalize a mix/match of ids and uids to a list of uids.
        if (isset($config['availableVolumes']) && is_array($config['availableVolumes'])) {
            $ids = [];
            $uids = [];

            foreach ($config['availableVolumes'] as $availableVolume) {
                if (is_int($availableVolume)) {
                    $ids[] = $availableVolume;
                } else {
                    $uids[] = $availableVolume;
                }
            }

            if (!empty($ids)) {
                $uids = array_merge($uids, Db::uidsByIds('{{%volumes}}', $ids));
            }

            $config['availableVolumes'] = $uids;
        }

        // normalize a mix/match of ids and uids to a list of uids.
        if (isset($config['availableTransforms']) && is_array($config['availableTransforms'])) {
            $ids = [];
            $uids = [];

            foreach ($config['availableTransforms'] as $availableTransform) {
                if (is_int($availableTransform)) {
                    $ids[] = $availableTransform;
                } else {
                    $uids[] = $availableTransform;
                }
            }

            if (!empty($ids)) {
                $uids = array_merge($uids, Db::uidsByIds('{{%assettransforms}}', $ids));
            }

            $config['availableTransforms'] = $uids;
        }

        // configFile => redactorConfig
        if (isset($config['configFile'])) {
            $config['ckeditorConfig'] = ArrayHelper::remove($config, 'configFile');
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        $volumeOptions = [];
        /** @var $volume Volume */
        foreach (Craft::$app->getVolumes()->getPublicVolumes() as $volume) {
            if ($volume->hasUrls) {
                $volumeOptions[] = [
                    'label' => Html::encode($volume->name),
                    'value' => $volume->uid
                ];
            }
        }

        $transformOptions = [];
        foreach (Craft::$app->getAssetTransforms()->getAllTransforms() as $transform) {
            $transformOptions[] = [
                'label' => Html::encode($transform->name),
                'value' => $transform->uid
            ];
        }

        return Craft::$app->getView()->renderTemplate('ckeditor/_field_settings', [
            'field' => $this,
            'purifierConfigOptions' => $this->_getCustomConfigOptions('htmlpurifier'),
            'ckeditorConfigOptions' => $this->_getCustomConfigOptions('ckeditor'),
            'volumeOptions' => $volumeOptions,
            'transformOptions' => $transformOptions,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getContentColumnType(): string
    {
        return $this->columnType;
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        if ($value === null || $value instanceof \Twig_Markup) {
            return $value;
        }

        // TODO: See if this is still necessary after updating to latest CKEditor.
        if ($value === '<p>&nbsp;</p>') {
            return null;
        }

        // Prevent everyone from having to use the |raw filter when outputting RTE content
        return Template::raw($value);
    }

    /**
     * @inheritdoc
     */
    public function serializeValue($value, ElementInterface $element = null)
    {
        /** @var \Twig_Markup|null $value */
        if (!$value) {
            return null;
        }

        // Get the raw value
        $value = (string) $value;

        if (!$value) {
            return null;
        }

        if ($this->purifyHtml) {
            $value = HtmlPurifier::process($value, $this->_getPurifierConfig());
        }

        if (Craft::$app->getDb()->getIsMysql()) {
            // Encode any 4-byte UTF-8 characters.
            $value = StringHelper::encodeMb4($value);
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty($value, ElementInterface $element): bool
    {
        /** @var \Twig_Markup|null $value */
        return $value === null || parent::isValueEmpty((string) $value, $element);
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $id = $view->formatInputId($this->handle);
        $nsId = $view->namespaceInputId($id);
        $encValue = htmlentities((string) $value, ENT_NOQUOTES, 'UTF-8');
        $ckeditorConfig = $this->_getCkeditorConfig();
        $site = ($element ? $element->getSite() : Craft::$app->getSites()->getCurrentSite());

        $ckeditorConfig = Json::encode(array_merge($ckeditorConfig, [
            'craftcms' => [
                'elementSiteId' => $site->id,
                'volumes' => $this->_getVolumeKeys(),
                'transforms' => $this->_getTransforms()
            ]
        ]));

        $js = <<<JS
ClassicEditor
    .create(document.getElementById('{$nsId}'), {$ckeditorConfig})
    .then(function(editor) {
        $(editor.element).closest('form').on('submit', function() {
            editor.updateSourceElement();
        });
        editor.model.document.on( 'change:data', () => {
            editor.updateSourceElement();
        });
    })
;
JS;

        $css = <<<CSS
.ck-editor .ck-editor__editable_inline {
    padding: 1em 2em;
}
CSS;

        $view->registerAssetBundle(FieldAsset::class);
        $view->registerCss($css);
        $view->registerJs($js);

        return "<textarea style=\"display: none;\" id='{$id}' name='{$this->handle}'>{$encValue}</textarea>";
    }

    /**
     * @inheritdoc
     */
    public function getStaticHtml($value, ElementInterface $element): string
    {
        /** @var \Twig_Markup|null $value */
        return '<div class="text">' . ($value ?: '&nbsp;') . '</div>';
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the available Redactor config options.
     *
     * @param string $dir The directory name within the config/ folder to look for config files
     * @return array
     */
    private function _getCustomConfigOptions(string $dir): array
    {
        $options = ['' => Craft::t('app', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir;

        if (is_dir($path)) {
            $files = FileHelper::findFiles($path, [
                'only' => ['*.json'],
                'recursive' => false
            ]);

            foreach ($files as $file) {
                $options[pathinfo($file, PATHINFO_BASENAME)] = pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $options;
    }

    /**
     * Returns the HTML Purifier config used by this field.
     *
     * @return array
     */
    private function _getPurifierConfig(): array
    {
        if ($config = $this->_getConfig('htmlpurifier', $this->purifierConfig)) {
            return $config;
        }

        // Default config
        return [
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.EnableID' => true,
        ];
    }

    private function _getCkeditorConfig(): array
    {
        $config = $this->_getConfig('ckeditor', $this->ckeditorConfig);

        if ($config) {
            return $config;
        }

        // Default config
        return Json::decode('{}');
    }

    /**
     * Returns a JSON-decoded config, if it exists.
     *
     * @param string $dir The directory name within the config/ folder to look for the config file
     * @param string|null $file The filename to load
     * @return array|false The config, or false if the file doesn't exist
     */
    private function _getConfig(string $dir, string $file = null)
    {
        if (!$file) {
            return false;
        }

        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return false;
        }
        return Json::decode(file_get_contents($path));
    }

    /**
     * Returns the available volumes.
     *
     * @return string[]
     */
    private function _getVolumeKeys(): array
    {
        if (!$this->availableVolumes) {
            return [];
        }

        $criteria = ['parentId' => ':empty:'];

        if ($this->availableVolumes !== '*') {
            $criteria['volumeId'] = Db::idsByUids('{{%volumes}}', $this->availableVolumes);
        }

        $folders = Craft::$app->getAssets()->findFolders($criteria);

        // Sort volumes in the same order as they are sorted in the CP
        $sortedVolumeIds = Craft::$app->getVolumes()->getAllVolumeIds();
        $sortedVolumeIds = array_flip($sortedVolumeIds);

        $volumeKeys = [];

        usort($folders, function($a, $b) use ($sortedVolumeIds) {
            // In case Temporary volumes ever make an appearance in RTF modals, sort them to the end of the list.
            $aOrder = $sortedVolumeIds[$a->volumeId] ?? PHP_INT_MAX;
            $bOrder = $sortedVolumeIds[$b->volumeId] ?? PHP_INT_MAX;

            return $aOrder - $bOrder;
        });

        foreach ($folders as $folder) {
            $volumeKeys[] = 'folder:'.$folder->uid;
        }

        return $volumeKeys;
    }

    /**
     * Get available transforms.
     *
     * @return array
     */
    private function _getTransforms(): array
    {
        if (!$this->availableTransforms) {
            return [];
        }

        $allTransforms = Craft::$app->getAssetTransforms()->getAllTransforms();
        $transformList = [];

        foreach ($allTransforms as $transform) {
            if (!is_array($this->availableTransforms) || in_array($transform->uid, $this->availableTransforms, false)) {
                $transformList[] = [
                    'handle' => Html::encode($transform->handle),
                    'name' => Html::encode($transform->name)
                ];
            }
        }

        return $transformList;
    }
}
