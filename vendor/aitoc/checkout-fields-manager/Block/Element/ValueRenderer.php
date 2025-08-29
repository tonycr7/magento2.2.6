<?php
/**
 *  Copyright © Aitoc. All rights reserved.
 */

namespace Aitoc\CheckoutFieldsManager\Block\Element;

use Magento\Config\Model\Config\Source\Yesno;
use Magento\Framework\Escaper;

/**
 * Render Checkout fields value for
 */
class ValueRenderer
{
    /**
     * @var Yesno
     */
    private $yesNo;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @param Yesno $yesno
     * @param Escaper $escaper
     */
    public function __construct(Yesno $yesno, Escaper $escaper)
    {
        $this->yesNo = $yesno;
        $this->escaper = $escaper;
    }

    /**
     * Prepare attribute label and value without inputs
     *
     * @param array $field
     * @param bool|true $optionsInLine
     *
     * @return string
     */
    public function renderFieldValueHtml($field = [], $optionsInLine = true)
    {
        $formattedLabel = $this->getFormattedLabel($field);
        $htmlEscapedLabel = $this->escaper->escapeHtml($formattedLabel);

        $formattedValue = $this->getFormattedValue($field, $optionsInLine);
        $htmlEscapedValue = $this->escaper->escapeHtml($formattedValue);

        return '<th>' . $htmlEscapedLabel . '</th><td>' . $htmlEscapedValue . '</td>';
    }

    /**
     * @param array $field
     *
     * @return string
     */
    public function getFormattedLabel($field)
    {
        return __($field['field_name']);
    }

    /**
     * @param array $field
     * @param bool|true $optionsInLine
     *
     * @return string without HTML (can be used for PDF)
     */
    public function getFormattedValue($field, $optionsInLine = true)
    {
        if ($field['value'] == null) {
            return '';
        }
        if ((strpos($field['value'], "\n")) !== false) {
            $value = substr($field['value'], strpos($field['value'], "\n") + 1);
        } else {
            $value = $field['value'];
        }
        switch ($field['type']) {
            case 'textarea':
                break;
            case 'date':
                break;
            case 'boolean':
                $options = $this->yesNo->toArray();
                $value = $options[$value];
                break;
            case 'checkbox':
            case 'select':
            case 'radiobutton':
            case 'multiselect':
                $options = $this->replaceOptionsLabels($field, explode("\n", $value));
                $value = $this->formatOptions($options, $optionsInLine);
                break;
        }

        return $value;
    }

    /**
     * replace values with option_id to option label
     *
     * @param array $field
     * @param array $selected
     *
     * @return array
     */
    private function replaceOptionsLabels($field, $selected)
    {
        if (!array_key_exists('options', $field) || !is_array($field['options']) || !is_array($selected)) {
            return [];
        }
        $result = [];
        foreach ($field['options'] as $option) {
            if (in_array($option['value'], $selected)) {
                $result[] = $option['label'];
            }
        }

        return $result;
    }

    /**
     * Implode array of options to string
     *
     * @param array $options
     * @param bool $optionsInLine
     *
     * @return string
     */
    private function formatOptions($options, $optionsInLine)
    {
        if (empty($options)) {
            return '';
        }

        if ($optionsInLine) {
            return implode(', ', $options);
        }

        return '<div>' . implode('</div><div>', $options) . '</div>';
    }
}
