<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Ps_CategoryTree extends Module implements WidgetInterface
{
    /**
     * @var string Name of the module running on PS 1.6.x. Used for data migration.
     */
    const PS_16_EQUIVALENT_MODULE = 'blockcategories';

    /**
     * @var int A way to display the category tree: Home category
     */
    const CATEGORY_ROOT_HOME = 0;

    /**
     * @var int A way to display the category tree: Current category
     */
    const CATEGORY_ROOT_CURRENT = 1;

    /**
     * @var int A way to display the category tree: Parent category
     */
    const CATEGORY_ROOT_PARENT = 2;

    /**
     * @var int A way to display the category tree: Current category and its parent (if exists)
     */
    const CATEGORY_ROOT_CURRENT_PARENT = 3;

    public function __construct()
    {
        $this->name = 'ps_categorytree';
        $this->tab = 'front_office_features';
        $this->version = '2.0.3';
        $this->author = 'PrestaShop';

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->getTranslator()->trans('Category tree links', [], 'Modules.Categorytree.Admin');
        $this->description = $this->getTranslator()->trans('Help navigation on your store, show your visitors current category and subcategories.', [], 'Modules.Categorytree.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.1.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        // If the PS 1.6 module wasn't here, set the default values
        if (!$this->uninstallPrestaShop16Module()) {
            Configuration::updateValue('BLOCK_CATEG_MAX_DEPTH', 4);
            Configuration::updateValue('BLOCK_CATEG_ROOT_CATEGORY', 1);
        }

        return parent::install()
            && $this->registerHook('displayLeftColumn');
    }

    /**
     * Migrate data from 1.6 equivalent module (if applicable), then uninstall
     */
    public function uninstallPrestaShop16Module()
    {
        if (!Module::isInstalled(self::PS_16_EQUIVALENT_MODULE)) {
            return false;
        }
        $oldModule = Module::getInstanceByName(self::PS_16_EQUIVALENT_MODULE);
        if ($oldModule) {
            // This closure calls the parent class to prevent data to be erased
            // It allows the new module to be configured without migration
            $parentUninstallClosure = function () {
                return parent::uninstall();
            };
            $parentUninstallClosure = $parentUninstallClosure->bindTo($oldModule, get_class($oldModule));
            $parentUninstallClosure();
        }

        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('BLOCK_CATEG_MAX_DEPTH') ||
            !Configuration::deleteByName('BLOCK_CATEG_ROOT_CATEGORY')) {
            return false;
        }

        return true;
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitBlockCategories')) {
            $maxDepth = (int) (Tools::getValue('BLOCK_CATEG_MAX_DEPTH'));
            if ($maxDepth < 0) {
                $output .= $this->displayError($this->getTranslator()->trans('Maximum depth: Invalid number.', [], 'Admin.Notifications.Error'));
            } else {
                Configuration::updateValue('BLOCK_CATEG_MAX_DEPTH', (int) $maxDepth);
                Configuration::updateValue('BLOCK_CATEG_SORT_WAY', Tools::getValue('BLOCK_CATEG_SORT_WAY'));
                Configuration::updateValue('BLOCK_CATEG_SORT', Tools::getValue('BLOCK_CATEG_SORT'));
                Configuration::updateValue('BLOCK_CATEG_ROOT_CATEGORY', Tools::getValue('BLOCK_CATEG_ROOT_CATEGORY'));

                Tools::redirectAdmin(AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&conf=6');
            }
        }

        return $output . $this->renderForm();
    }

    /**
     * Format category into an array compatible with existing templates
     */
    private function formatCategory($category)
    {
        if (isset($category['children'])) {
            $children = array_map([$this, 'formatCategory'], $category['children']);
        } else {
            $children = [];
        }

        return [
            'id' => $category['id_category'],
            'link' => $this->context->link->getCategoryLink($category['id_category'], $category['link_rewrite']),
            'name' => $category['name'],
            'desc' => $category['description'],
            'children' => $children,
        ];
    }

    private function getCategories($category)
    {
        $idCategory = null;
        $maxdepth = Configuration::get('BLOCK_CATEG_MAX_DEPTH');
        if (Validate::isLoadedObject($category)) {
            $idCategory = $category->id;
            if ($maxdepth > 0) {
                $maxdepth += $category->level_depth;
            }
        }

        $groups = Customer::getGroupsStatic((int) $this->context->customer->id);
        $sqlFilter = $maxdepth ? 'AND c.`level_depth` <= ' . (int) $maxdepth : '';
        $orderBy = ' ORDER BY c.`level_depth` ASC, ' . (Configuration::get('BLOCK_CATEG_SORT') ? 'cl.`name`' : 'category_shop.`position`') . ' ' . (Configuration::get('BLOCK_CATEG_SORT_WAY') ? 'DESC' : 'ASC');
        $categories = Category::getNestedCategories($idCategory, $this->context->language->id, true, $groups, true, $sqlFilter, $orderBy);

        $categories = array_map([$this, 'formatCategory'], $categories);

        return array_shift($categories);
    }

    /**
     * @deprecated 2.0.4
     */
    public function getTree($resultParents, $resultIds, $maxDepth, $id_category = null, $currentDepth = 0)
    {
        if (is_null($id_category)) {
            $id_category = $this->context->shop->getCategory();
        }

        $children = [];

        if (isset($resultParents[$id_category]) && count($resultParents[$id_category]) && ($maxDepth == 0 || $currentDepth < $maxDepth)) {
            foreach ($resultParents[$id_category] as $subcat) {
                $children[] = $this->getTree($resultParents, $resultIds, $maxDepth, $subcat['id_category'], $currentDepth + 1);
            }
        }

        if (isset($resultIds[$id_category])) {
            $link = $this->context->link->getCategoryLink($id_category, $resultIds[$id_category]['link_rewrite']);
            $name = $resultIds[$id_category]['name'];
            $desc = $resultIds[$id_category]['description'];
        } else {
            $link = $name = $desc = '';
        }

        return [
            'id' => $id_category,
            'link' => $link,
            'name' => $name,
            'desc' => $desc,
            'children' => $children,
        ];
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->getTranslator()->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'radio',
                        'label' => $this->getTranslator()->trans('Category root', [], 'Modules.Categorytree.Admin'),
                        'name' => 'BLOCK_CATEG_ROOT_CATEGORY',
                        'hint' => $this->getTranslator()->trans('Select which category is displayed in the block. The current category is the one the visitor is currently browsing.', [], 'Modules.Categorytree.Admin'),
                        'values' => [
                            [
                                'id' => 'home',
                                'value' => static::CATEGORY_ROOT_HOME,
                                'label' => $this->getTranslator()->trans('Home category', [], 'Modules.Categorytree.Admin'),
                            ],
                            [
                                'id' => 'current',
                                'value' => static::CATEGORY_ROOT_CURRENT,
                                'label' => $this->getTranslator()->trans('Current category', [], 'Modules.Categorytree.Admin'),
                            ],
                            [
                                'id' => 'parent',
                                'value' => static::CATEGORY_ROOT_PARENT,
                                'label' => $this->getTranslator()->trans('Parent category', [], 'Modules.Categorytree.Admin'),
                            ],
                            [
                                'id' => 'current_parent',
                                'value' => static::CATEGORY_ROOT_CURRENT_PARENT,
                                'label' => $this->getTranslator()->trans('Current category, unless it has no subcategories, in which case the parent category of the current category is used', [], 'Modules.Categorytree.Admin'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->getTranslator()->trans('Maximum depth', [], 'Modules.Categorytree.Admin'),
                        'name' => 'BLOCK_CATEG_MAX_DEPTH',
                        'desc' => $this->getTranslator()->trans('Set the maximum depth of category sublevels displayed in this block (0 = infinite).', [], 'Modules.Categorytree.Admin'),
                    ],
                    [
                        'type' => 'radio',
                        'label' => $this->getTranslator()->trans('Sort', [], 'Admin.Actions'),
                        'name' => 'BLOCK_CATEG_SORT',
                        'values' => [
                            [
                                'id' => 'name',
                                'value' => 1,
                                'label' => $this->getTranslator()->trans('By name', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'position',
                                'value' => 0,
                                'label' => $this->getTranslator()->trans('By position', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'radio',
                        'label' => $this->getTranslator()->trans('Sort order', [], 'Admin.Actions'),
                        'name' => 'BLOCK_CATEG_SORT_WAY',
                        'values' => [
                            [
                                'id' => 'name',
                                'value' => 1,
                                'label' => $this->getTranslator()->trans('Descending', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'position',
                                'value' => 0,
                                'label' => $this->getTranslator()->trans('Ascending', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->getTranslator()->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->submit_action = 'submitBlockCategories';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
        ];

        return $helper->generateForm([$fields_form]);
    }

    public function getConfigFieldsValues()
    {
        return [
            'BLOCK_CATEG_MAX_DEPTH' => Tools::getValue('BLOCK_CATEG_MAX_DEPTH', Configuration::get('BLOCK_CATEG_MAX_DEPTH')),
            'BLOCK_CATEG_SORT_WAY' => Tools::getValue('BLOCK_CATEG_SORT_WAY', Configuration::get('BLOCK_CATEG_SORT_WAY')),
            'BLOCK_CATEG_SORT' => Tools::getValue('BLOCK_CATEG_SORT', Configuration::get('BLOCK_CATEG_SORT')),
            'BLOCK_CATEG_ROOT_CATEGORY' => Tools::getValue('BLOCK_CATEG_ROOT_CATEGORY', Configuration::get('BLOCK_CATEG_ROOT_CATEGORY')),
        ];
    }

    public function setLastVisitedCategory()
    {
        if (method_exists($this->context->controller, 'getCategory') && ($category = $this->context->controller->getCategory())) {
            $this->context->cookie->last_visited_category = $category->id;
        } elseif (method_exists($this->context->controller, 'getProduct') && ($product = $this->context->controller->getProduct())) {
            if (!isset($this->context->cookie->last_visited_category)
                || !Product::idIsOnCategoryId($product->id, [['id_category' => $this->context->cookie->last_visited_category]])
                || !Category::inShopStatic($this->context->cookie->last_visited_category, $this->context->shop)
            ) {
                $this->context->cookie->last_visited_category = (int) $product->id_category_default;
            }
        }
    }

    public function renderWidget($hookName = null, array $configuration = [])
    {
        $this->setLastVisitedCategory();
        $this->smarty->assign($this->getWidgetVariables($hookName, $configuration));

        return $this->fetch('module:ps_categorytree/views/templates/hook/ps_categorytree.tpl');
    }

    public function getWidgetVariables($hookName = null, array $configuration = [])
    {
        if (Configuration::get('BLOCK_CATEG_ROOT_CATEGORY') && !empty($this->context->cookie->last_visited_category) && $this->context->controller instanceof CategoryController) {
            $category = new Category($this->context->cookie->last_visited_category, $this->context->language->id);
        } else {
            $category = new Category((int) Configuration::get('PS_HOME_CATEGORY'), $this->context->language->id);
        }

        if (Configuration::get('BLOCK_CATEG_ROOT_CATEGORY') == static::CATEGORY_ROOT_PARENT && !$category->is_root_category && $category->id_parent) {
            $category = new Category($category->id_parent, $this->context->language->id);
        } elseif (Configuration::get('BLOCK_CATEG_ROOT_CATEGORY') == static::CATEGORY_ROOT_CURRENT_PARENT && !$category->is_root_category && !$category->getSubCategories($category->id, true)) {
            $category = new Category($category->id_parent, $this->context->language->id);
        }

        return [
            'categories' => $this->getCategories($category),
            'currentCategory' => $category->id,
        ];
    }
}
