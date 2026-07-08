<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SiteWidget\Plugin\System\SiteWidget\Extension\SiteWidget;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): SiteWidget {
                $plugin = new SiteWidget(
                    $container->get('dispatcher'),
                    (array)PluginHelper::getPlugin('system', 'sitewidget')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
