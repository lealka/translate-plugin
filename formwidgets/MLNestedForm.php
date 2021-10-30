<?php namespace RainLab\Translate\FormWidgets;

use Backend\FormWidgets\NestedForm;
use RainLab\Translate\Models\Locale;
use October\Rain\Html\Helper as HtmlHelper;
use ApplicationException;
use Request;

/**
 * ML NestedForm
 * Renders a multi-lingual nestedform field.
 *
 * @package rainlab\translate
 * @author Luke Towers
 */
class MLNestedForm extends NestedForm
{
    use \RainLab\Translate\Traits\MLControl;

    /**
     * {@inheritDoc}
     */
    protected $defaultAlias = 'mlnestedform';

    /**
     * {@inheritDoc}
     */
    public function init()
    {
        parent::init();
        $this->initLocale();
    }

    /**
     * {@inheritDoc}
     */
    public function render()
    {
        $this->actAsParent();
        $parentContent = parent::render();
        $this->actAsParent(false);

        if (!$this->isAvailable) {
            return $parentContent;
        }

        $this->vars['nestedform'] = $parentContent;
        return $this->makePartial('mlnestedform');
    }

    public function prepareVars()
    {
        parent::prepareVars();
        $this->prepareLocaleVars();
    }

    /**
     * Returns an array of translated values for this field
     * @return array
     */
    public function getSaveValue($value)
    {
        $this->rewritePostValues();

        return $this->getLocaleSaveValue(is_array($value) ? array_values($value) : $value);
    }

    /**
     * {@inheritDoc}
     */
    protected function loadAssets()
    {
        $this->actAsParent();
        parent::loadAssets();
        $this->actAsParent(false);

        if (Locale::isAvailable()) {
            $this->loadLocaleAssets();
            $this->addJs('js/mlnestedform.js');
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getParentViewPath()
    {
        return base_path().'/modules/backend/formwidgets/nestedform/partials';
    }

    /**
     * {@inheritDoc}
     */
    protected function getParentAssetPath()
    {
        return '/modules/backend/formwidgets/nestedform/assets';
    }

    public function onSwitchItemLocale()
    {
        if (!$locale = post('_nestedform_locale')) {
            throw new ApplicationException('Unable to find a nestedform locale for: '.$locale);
        }

        // Store previous value
        $previousLocale = post('_nestedform_previous_locale');
        $previousValue = $this->getPrimarySaveDataAsArray();

        // Update widget to show form for switched locale
        $lockerData = $this->getLocaleSaveDataAsArray($locale) ?: [];
        $this->reprocessLocaleItems($lockerData);

        foreach ($this->formWidgets as $key => $widget) {
            $value = array_shift($lockerData);
            if (!$value) {
                unset($this->formWidgets[$key]);
            }
            else {
                $widget->setFormValues($value);
            }
        }

        $this->actAsParent();
        $parentContent = parent::render();
        $this->actAsParent(false);

        return [
            '#'.$this->getId('mlNestedForm') => $parentContent,
            'updateValue' => json_encode($previousValue),
            'updateLocale' => $previousLocale,
        ];
    }

    /**
     * Ensure that the current locale data is processed by the repeater instead of the original non-translated data
     * @return void
     */
    protected function reprocessLocaleItems($data)
    {
        $this->formWidgets = [];
        $this->formField->value = $data;

        $key = implode('.', HtmlHelper::nameToArray($this->formField->getName()));
        $requestData = Request::all();
        array_set($requestData, $key, $data);
        Request::merge($requestData);

        $this->processItems();
    }

    /**
     * Gets the active values from the selected locale.
     * @return array
     */
    protected function getPrimarySaveDataAsArray()
    {
        $data = post($this->formField->getName()) ?: [];

        return $this->processSaveValue($data);
    }

    /**
     * Returns the stored locale data as an array.
     * @return array
     */
    protected function getLocaleSaveDataAsArray($locale)
    {
        $saveData = array_get($this->getLocaleSaveData(), $locale, []);

        if (!is_array($saveData)) {
            $saveData = json_decode($saveData, true);
        }

        return $saveData;
    }

    /**
     * Since the locker does always contain the latest values, this method
     * will take the save data from the repeater and merge it in to the
     * locker based on which ever locale is selected using an item map
     * @return void
     */
    protected function rewritePostValues()
    {
        /*
         * Get the selected locale at postback
         */
        $data = post('RLTranslateNestedFormLocale');
        $fieldName = implode('.', HtmlHelper::nameToArray($this->fieldName));
        $locale = array_get($data, $fieldName);

        if (!$locale) {
            return;
        }

        /*
         * Splice the save data in to the locker data for selected locale
         */
        $data = $this->getPrimarySaveDataAsArray();

        //@FIXME: HACK by Al-Ka
        $fieldName = class_basename($this->formWidget->model) . '.' . implode('.', HtmlHelper::nameToArray($this->fieldName));
        //END: HACK by Al-Ka

        $requestData = Request::all();
        //@FIXME: HACK by Al-Ka
        array_set($requestData, $fieldName, $data);
        //END: HACK by Al-Ka
        Request::merge($requestData);
    }

    //FIXME: HACK by Al-Ka
    /**
     * processSaveValue splices in some meta data (group and index values) to the dataset
     * @param array $value
     * @return array|null
     */
    protected function processSaveValue($value)
    {
        $values = $this->getLocaleSaveData();

        return $values['en'];
    }

    /**
     * Returns an array of translated values for this field
     * @return array
     */
    public function getLocaleSaveData()
    {
        $values = [];
        $post = post();
        $data = $post['RLTranslate'];

        if (!is_array($data)) {
            return $values;
        }

        $fieldName = implode('.', HtmlHelper::nameToArray($this->fieldName));
        $fullValue = $post[class_basename($this->formWidget->model)][$fieldName];
        $enValue = $data['en']['mlnf'][$fieldName];
        foreach ($data as $locale => $_data) {
            $value = $fullValue;
            $value = $this->getLocaleSaveDataItem($value, $enValue, $_data['mlnf'][$fieldName]);

            $values[$locale] = $value;
        }

        return $values;
    }

    private function getLocaleSaveDataItem($value, $enValue, $localValue)
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                if (array_key_exists($k, $enValue)) {
                    $value[$k] = $this->getLocaleSaveDataItem($v, $enValue[$k], $localValue[$k]);
                } else {
                    $value[$k] = $v;
                }
            } else {
                $value[$k] = $v;
                if (array_key_exists($k, $localValue) &&
                    ($localValue[$k] || $localValue[$k] === 0)
                ) {
                    $value[$k] = $localValue[$k];
                } elseif (array_key_exists($k, $enValue)) {
                    $value[$k] = $enValue[$k];
                }
            }
        }

        return $value;
    }
    //END: HACK by Al-Ka
}
