<?php

declare(strict_types=1);

/**
 * @author Amasty Team
 * @copyright Copyright (c) Amasty (https://www.amasty.com)
 * @package Improved Layered Navigation Base for Magento 2
 */

namespace Amasty\Shopby\Block\Navigation;

use Amasty\Shopby\Helper\Data as ShopbyHelper;
use Amasty\Shopby\Helper\FilterSetting;
use Amasty\Shopby\Model\Config\MobileConfigResolver;
use Amasty\Shopby\Model\ConfigProvider;
use Amasty\Shopby\Model\Layer\Filter\Item;
use Amasty\Shopby\Model\Layer\Filter\Decimal as DecimalFilter;
use Amasty\Shopby\Model\Layer\Filter\Price as PriceFilter;
use Amasty\Shopby\Model\Price\GetPrecisionValue;
use Amasty\ShopbyBase\Api\Data\FilterSettingInterface;
use Amasty\ShopbyBase\Api\UrlBuilderInterface;
use Amasty\ShopbyBase\Model\FilterSetting\FilterResolver;
use Amasty\ShopbyBase\Model\FilterSetting\IsMultiselect;
use Amasty\ShopbyBase\Model\FilterSetting\IsShowProductQuantities;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\BlockFactory;
use Magento\Framework\View\Element\Template\Context;

class State extends \Magento\LayeredNavigation\Block\Navigation\State
{
    /**
     * @var string
     */
    protected $_template = 'layer/state.phtml';

    /**
     * @var FilterSetting
     */
    private $filterSettingHelper;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $managerInterface;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var ShopbyHelper
     */
    private $helper;

    /**
     * @var BlockFactory
     */
    private $blockFactory;

    /**
     * @var IsShowProductQuantities
     */
    private $isShowProductQuantities;

    /**
     * @var IsMultiselect
     */
    private $isMultiselect;

    /**
     * @var GetPrecisionValue
     */
    private $getPrecisionValue;

    /**
     * @var MobileConfigResolver
     */
    private $mobileConfigResolver;

    /**
     * @var FilterResolver
     */
    private $filterResolver;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    public function __construct(
        Context $context,
        Resolver $layerResolver,
        FilterSetting $filterSettingHelper,
        PriceCurrencyInterface $priceCurrency,
        ShopbyHelper $helper,
        BlockFactory $blockFactory,
        UrlBuilderInterface $urlBuilder,
        IsShowProductQuantities $isShowProductQuantities,
        IsMultiselect $isMultiselect,
        GetPrecisionValue $getPrecisionValue,
        MobileConfigResolver $mobileConfigResolver,
        FilterResolver $filterResolver,
        ConfigProvider $configProvider,
        array $data = []
    ) {
        $this->filterSettingHelper = $filterSettingHelper;
        $this->managerInterface = $context->getStoreManager();
        $this->priceCurrency = $priceCurrency;
        $this->helper = $helper;
        $this->blockFactory = $blockFactory;
        parent::__construct($context, $layerResolver, $data);
        $this->_urlBuilder = $urlBuilder;
        $this->isShowProductQuantities = $isShowProductQuantities;
        $this->isMultiselect = $isMultiselect;
        $this->getPrecisionValue = $getPrecisionValue;
        $this->mobileConfigResolver = $mobileConfigResolver;
        $this->filterResolver = $filterResolver;
        $this->configProvider = $configProvider;
    }

    /**
     * @param \Magento\Catalog\Model\Layer\Filter\FilterInterface $filter
     * @return \Amasty\ShopbyBase\Api\Data\FilterSettingInterface
     */
    public function getFilterSetting(\Magento\Catalog\Model\Layer\Filter\FilterInterface $filter)
    {
        return $this->filterResolver->resolveByFilter($filter);
    }

    /**
     * @param Item $filter
     * @param bool $showLabels
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getSwatchHtml(Item $filter, $showLabels = false)
    {
        return $this->getLayout()->createBlock(\Amasty\Shopby\Block\Navigation\State\Swatch::class)
            ->setFilter($filter)
            ->showLabels($showLabels)
            ->toHtml();
    }

    /**
     * @return string
     */
    public function collectFilters()
    {
        return $this->mobileConfigResolver->getSubmitFilterMode();
    }

    /**
     * @return int
     */
    public function getUnfoldedCount()
    {
        return $this->configProvider->getUnfoldedCount();
    }

    /**
     * @return string
     */
    public function createShowMoreButtonBlock()
    {
        return $this->blockFactory->createBlock(\Amasty\Shopby\Block\Navigation\Widget\HideMoreOptions::class)
            ->setIsState(true)
            ->setUnfoldedOptions($this->getUnfoldedCount())
            ->toHtml();
    }

    /**
     * @param Item $filter
     * @return string
     */
    public function viewLabel($filter)
    {
        $filterSetting = $this->getFilterSetting($filter->getFilter());

        switch ($filterSetting->getDisplayMode()) {
            case \Amasty\Shopby\Model\Source\DisplayMode::MODE_IMAGES:
                $value =  $this->getSwatchHtml($filter);
                break;
            case \Amasty\Shopby\Model\Source\DisplayMode::MODE_TEXT_SWATCH:
                $value =  $this->getSwatchHtml($filter);
                break;
            case \Amasty\Shopby\Model\Source\DisplayMode::MODE_IMAGES_LABELS:
                $value =  $this->getSwatchHtml($filter, true);
                break;
            default:
                $value = $this->viewExtendedLabel($filter);
                break;
        }

        return $value;
    }

    /**
     * @param Item $filter
     * @return string
     */
    private function viewExtendedLabel($filter)
    {
        if ($filter->getFilter()->getRequestVar() == \Amasty\Shopby\Model\Source\DisplayMode::ATTRUBUTE_PRICE) {
            $currencyRate = (float) $filter->getFilter()->getCurrencyRate();

            if ($currencyRate != 1) {
                $value = $this->generateValueLabel($filter);
            } else {
                $value = $filter->getOptionLabel();
            }
        } else {
            $value = $this->stripTags($filter->getOptionLabel());
        }

        return $value;
    }

    /**
     * @param $filterItem
     * @param $currencyRate
     * @return \Magento\Framework\Phrase
     */
    private function generateValueLabel($filterItem)
    {
        $arguments = $filterItem->getLabel()->getArguments();
        $filter = $filterItem->getFilter();
        $filterSetting = $this->filterResolver->resolveByFilter($filter);

        if (!isset($arguments[1])) {
            $arguments[1] = "";
        }

        $arguments[0] = preg_replace("/[^,.0-9]/", '', $arguments[0]);
        $arguments[1] = preg_replace("/[^,.0-9]/", '', $arguments[1]);

        $posDotInFrom = strpos($arguments[0], '.');
        $posDotInTo = strpos($arguments[1], '.');
        $posCommaInFrom = strpos($arguments[0], ',');
        $posCommaInTo = strpos($arguments[1], ',');

        $arguments[0] = $this->removeSeparator($posDotInFrom, $posCommaInFrom, $arguments[0]);
        $arguments[1] = $this->removeSeparator($posDotInTo, $posCommaInTo, $arguments[1]);

        $arguments[0] = preg_replace("/[']/", '', $arguments[0]);
        $arguments[1] = preg_replace("/[']/", '', $arguments[1]);

        $value = __(
            '%1 - %2',
            $this->generateSpanPrice($filterSetting, $arguments[0]),
            $this->generateSpanPrice($filterSetting, $arguments[1], true)
        );

        return $value;
    }

    /**
     * @param $posDot
     * @param $posComma
     * @param $value
     * @return string
     */
    private function removeSeparator($posDot, $posComma, $value)
    {
        if ($posDot !== false && $posComma !== false) {
            if ($posDot < $posComma) {
                $value = preg_replace("/[.]/", '', $value);
            } else {
                $value = preg_replace("/[,]/", '', $value);
            }
        }

        return $value;
    }

    private function generateSpanPrice(
        FilterSettingInterface $filterSetting,
        string $value,
        bool $flagTo = false
    ): string {
        if ($value === '' && $flagTo) {
            $resultPrice = __('above');
        } else {
            $precision = $this->getPrecisionValue->execute($filterSetting, (float)$value);
            $resultPrice = $this->priceCurrency->format((float)$value, true, $precision);
        }

        return '<span class="price">' . $resultPrice . '</span>';
    }

    /**
     * @param $value
     * @param $filterItem
     * @return float|string
     */
    public function getFilterValue($value, $filterItem)
    {
        $filter = $filterItem->getFilter();
        $isPrice = ($filter instanceof PriceFilter || $filter instanceof DecimalFilter) && count($value) >= 2;

        if (!$isPrice && is_array($value)) {
            $value = $value[0];
        }

        return $value;
    }

    /**
     * @param $resultValue
     * @return string
     */
    public function getDataValue($resultValue)
    {
        if ($resultValue === null) {
            return null;
        }

        if (is_numeric($resultValue)) {
            $value = $resultValue;
        } else {
            $value = $this->escapeHtml(
                $this->stripTags(is_array($resultValue) ? implode('-', $resultValue) : $resultValue, false)
            );
        }

        return $value;
    }

    /**
     * @param $filter
     * @param $value
     * @return array
     */
    public function changeValueForMultiselect($filter, $value)
    {
        if ($filter instanceof PriceFilter || $filter instanceof DecimalFilter) {
            $value = [];
        } else {
            $value = array_filter(array_slice((array)$value, 1));
        }

        return $value;
    }

    public function isFilterItemSelected(Item $filterItem): bool
    {
        return (bool) $this->helper->isFilterItemSelected($filterItem);
    }

    /**
     * Retrieve Clear Filters URL
     *
     * @return string
     */
    public function getClearUrl()
    {
        $filterState = [
            'df' => null,
            'dt' => null,
            'price-ranges' => null
        ];
        foreach ($this->getActiveFilters() as $item) {
            $filterState[$item->getFilter()->getRequestVar()] = $item->getFilter()->getCleanValue();
        }
        $params['_current'] = true;
        $params['_use_rewrite'] = true;
        $params['_query'] = $filterState;
        $params['_escape'] = true;
        return $this->_urlBuilder->getUrl('*/*/*', $params);
    }

    public function isShowProductQuantities(?int $showProductQuantities): bool
    {
        return $this->isShowProductQuantities->execute($showProductQuantities);
    }

    public function isMultiselect(FilterSettingInterface $filterSetting): bool
    {
        return $this->isMultiselect->execute(
            $filterSetting->getAttributeCode(),
            $filterSetting->isMultiselect(),
            $filterSetting->getDisplayMode()
        );
    }
}
