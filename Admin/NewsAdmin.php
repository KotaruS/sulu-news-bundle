<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Admin;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\SuluUtils\Admin\Builder;
use Kotaru\SuluUtils\Traits\AdminHelpersTrait;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\View\TogglerToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\DropdownToolbarAction;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\ActivityBundle\Infrastructure\Sulu\Admin\View\ActivityViewBuilderFactoryInterface;
use Kotaru\SuluUtils\Content\Toolbar\TogglerToolbarAction as ListTogglerToolbarAction;

class NewsAdmin extends Admin
{
    use AdminHelpersTrait;

    public const SECURITY_CONTEXT_GROUP = 'News';
    public const ADMIN_KEY = 'app_admin';

    public const SECURITY_CONTEXT = 'news';

    public const IMPORT_LIST_KEY = 'news_import';

    public const LIST_KEY = 'news';

    public const VIEW = 'app.news';

    public const SETTINGS_VIEW = 'app.news_settings';

    public const LIST_VIEW = 'app.news_list';

    public const IMPORT_VIEW = 'app.news_import';

    public const IMPORT_FORM_KEY = 'news_import';

    public const DETAIL_FORM_KEY = 'news';

    public const ADD_FORM_VIEW = 'app.news_add_form';

    public const EDIT_FORM_VIEW = 'app.news_edit_form';


    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly ActivityViewBuilderFactoryInterface $activityViewBuilderFactory,
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly SecurityCheckerInterface $securityChecker,

    ) {
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if ($this->protect(PermissionTypes::VIEW)) {
            $module = new NavigationItem('app.news');
            $module->setPosition(30);
            $module->setIcon('su-news');
            $module->setView(static::LIST_VIEW);

            $navigationItemCollection->add($module);

            $settings = new NavigationItem('app.news_settings');
            $settings->setPosition(256);
            $settings->setView(static::SETTINGS_VIEW);

            $navigationItemCollection->get(Admin::SETTINGS_NAVIGATION_ITEM)->addChild($settings);
        }
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        $locales = $this->webspaceManager->getAllLocales();
        $webspaces = $this->webspaceManager->getWebspaceCollection()->getWebspaces();
        $defaultLocale = $webspaces[\array_key_first($webspaces)]->getDefaultLocalization()->getLocale();

        $listToolbars = $this->getToolbars([
            ['sulu_admin.add', PermissionTypes::ADD],
            ['sulu_admin.delete', PermissionTypes::DELETE],
        ]);

        $listToolbars[] = new ListTogglerToolbarAction(
            'app.news.show_external',
            'showExternal',
            false,
        );

        $addFormToolbars = $this->getToolbars([
            ['sulu_admin.save', PermissionTypes::ADD],
        ]);

        $editFormToolbars = $this->getToolbars([
            ['sulu_admin.save', PermissionTypes::EDIT],
        ]);

        $editFormToolbars[] = new DropdownToolbarAction(
            'sulu_admin.edit',
            'su-pen',
            [
                new ToolbarAction('sulu_admin.copy'),
                new ToolbarAction('sulu_admin.copy_locale'),
            ]
        );

        if ($this->protect(PermissionTypes::DELETE)) {
            $editFormToolbars[] = new DropdownToolbarAction(
                'sulu_admin.delete',
                'su-trash-alt',
                [
                    new ToolbarAction(
                        'sulu_admin.delete'
                    ),
                    new ToolbarAction(
                        'sulu_admin.delete',
                        ['delete_locale' => true]
                    ),
                ]
            );
        }

        $editFormToolbars[] = new TogglerToolbarAction(
            'app.news.toggler_visible',
            'visible',
            'enable',
            'disable'
        );

        $editFormToolbars[] = new TogglerToolbarAction(
            'app.news.toggler_external',
            'external',
            'set-external',
            'unset-external'
        );

        // $editFormToolbars[] = new TogglerToolbarAction(
        //   'app.news.external',
        //   'external',
        //   'enable',
        //   'disable'
        // );

        if ($this->protect(PermissionTypes::VIEW)) {
            $mainView = $this->viewBuilderFactory
                ->createTabViewBuilder(static::VIEW, '/news');

            $viewCollection->add($mainView);

            $listView = $this->viewBuilderFactory
                ->createListViewBuilder(static::LIST_VIEW, '/:locale')
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->setListKey(static::LIST_KEY)
                ->setTitle('app.news')
                ->setTabTitle('app.news')
                ->addListAdapters(['table'])
                ->addLocales($locales)
                ->setParent(static::VIEW)
                ->setDefaultLocale($defaultLocale)
                ->addToolbarActions($listToolbars);
            if ($this->protect(PermissionTypes::ADD)) {
                $listView->setAddView(static::ADD_FORM_VIEW);
            }
            if ($this->protect(PermissionTypes::EDIT)) {
                $listView->setEditView(static::EDIT_FORM_VIEW);
            }

            $viewCollection->add($listView);

        }


        if ($this->protect(PermissionTypes::ADD)) {
            $addFormView = $this->viewBuilderFactory
                ->createResourceTabViewBuilder(static::ADD_FORM_VIEW, '/news/:locale/add')
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->addLocales($locales);
            if ($this->protect(PermissionTypes::VIEW)) {
                $addFormView->setBackView(static::LIST_VIEW);
            }
            $viewCollection->add($addFormView);

            $addFormDetails = $this->viewBuilderFactory
                ->createFormViewBuilder(static::ADD_FORM_VIEW . '.details', '/details')
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->setFormKey(static::DETAIL_FORM_KEY)
                ->setTabTitle('sulu_admin.details');
            if ($this->protect(PermissionTypes::EDIT)) {
                $addFormDetails->setEditView(static::EDIT_FORM_VIEW);
            }
            $addFormDetails->addToolbarActions($addFormToolbars)
                ->setParent(static::ADD_FORM_VIEW);

            $viewCollection->add($addFormDetails);
        }

        if ($this->protect(PermissionTypes::EDIT)) {
            $editFormView = $this->viewBuilderFactory
                ->createResourceTabViewBuilder(static::EDIT_FORM_VIEW, '/news/:locale/:id')
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->addLocales($locales);
            if ($this->protect(PermissionTypes::VIEW)) {
                $editFormView->setBackView(static::LIST_VIEW);
            }
            $viewCollection->add($editFormView);

            $editFormDetails = $this->viewBuilderFactory
                ->createPreviewFormViewBuilder(static::EDIT_FORM_VIEW . '.details', '/details')
                ->setPreviewCondition('id != null and external != true')
                ->disablePreviewWebspaceChooser()
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->setFormKey(static::DETAIL_FORM_KEY)
                ->setTabTitle('sulu_admin.details')
                ->addToolbarActions($editFormToolbars)
                ->setParent(static::EDIT_FORM_VIEW);

            $viewCollection->add($editFormDetails);

            $editSeoFormDetails = $this->viewBuilderFactory
                ->createFormViewBuilder(static::EDIT_FORM_VIEW . '.seo', '/seo')
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->setFormKey(static::DETAIL_FORM_KEY . '_seo')
                ->setTabTitle('sulu_page.seo')
                ->setTitleVisible(true)
                ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                ->setParent(static::EDIT_FORM_VIEW);

            $viewCollection->add($editSeoFormDetails);

            $editExcerptFormDetails = $this->viewBuilderFactory
                ->createFormViewBuilder(static::EDIT_FORM_VIEW . '.excerpt', '/excerpt')
                ->setResourceKey(NewsInterface::RESOURCE_KEY)
                ->setFormKey(static::DETAIL_FORM_KEY . '_ext')
                ->setTabTitle('sulu_page.excerpt')
                ->setTitleVisible(true)
                ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                ->setParent(static::EDIT_FORM_VIEW);

            $viewCollection->add($editExcerptFormDetails);

            if ($this->activityViewBuilderFactory->hasActivityListPermission()) {
                $newsActivityView = $this->activityViewBuilderFactory
                    ->createActivityListViewBuilder(
                        static::EDIT_FORM_VIEW . '.activity',
                        '/activity',
                        NewsInterface::RESOURCE_KEY
                    )
                    ->setParent(static::EDIT_FORM_VIEW);

                $viewCollection->add($newsActivityView);
            }

            // $editImportedForm = $this->viewBuilderFactory
            //   ->createFormViewBuilder(static::EDIT_FORM_VIEW . '.import', '/news/:locale/:id/import')
            //   ->setResourceKey(NewsInterface::RESOURCE_KEY)
            //   ->setFormKey(static::IMPORT_FORM_KEY)
            //   ->setTabTitle('app_admin.import_edit')
            //   ->addLocales($locales)
            // ;

            // $viewCollection->add($editImportedForm);
            $settingsView = (new Builder\GenericResourceTabBuilder(static::SETTINGS_VIEW, '/settings'))
                ->setResourceKey(NewsInterface::RESOURCE_KEY . '_settings')
                ->addRerenderAttribute('newsHomepage')
            ;
            $viewCollection->add($settingsView);


            $settingsFormView = (new Builder\GenericFormBuilder(static::SETTINGS_VIEW . '.form', '/news'))
                ->setResourceKey(NewsInterface::RESOURCE_KEY . '_settings')
                ->setTabTitle('app.news_settings.form')
                ->setFormKey('news_settings')
                ->addToolbarActions($this->getToolbars([
                    ['sulu_admin.save', PermissionTypes::ADD],
                ]))
                ->setParent(static::SETTINGS_VIEW)
            ;

            $viewCollection->add($settingsFormView);
        }

    }

    public function getConfigKey(): ?string
    {
        return static::ADMIN_KEY;
    }

    public function getSecurityContexts(): array
    {
        return [
            static::SULU_ADMIN_SECURITY_SYSTEM => [
                static::SECURITY_CONTEXT_GROUP => [
                    static::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::ADD,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                ],
            ],
        ];
    }


}
