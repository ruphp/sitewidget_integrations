<?php

namespace Opencart\Admin\Controller\Extension\SiteWidget\Module;

final class SiteWidget extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->load->language('extension/sitewidget/module/sitewidget');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST' && $this->validate()) {
            $this->model_setting_setting->editSetting('module_sitewidget', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module'));
        }

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module'),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/sitewidget/module/sitewidget', 'user_token=' . $this->session->data['user_token']),
        ];

        $data['save'] = $this->url->link('extension/sitewidget/module/sitewidget', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

        $keys = [
            'module_sitewidget_status',
            'module_sitewidget_public_key',
            'module_sitewidget_sms_api_token',
            'module_sitewidget_send_user_id',
            'module_sitewidget_send_roles',
            'module_sitewidget_send_email',
            'module_sitewidget_send_name',
        ];

        foreach ($keys as $key) {
            $data[$key] = $this->request->post[$key] ?? $this->config->get($key);
        }

        $data['module_sitewidget_send_user_id'] = $data['module_sitewidget_send_user_id'] ?? 1;
        $data['module_sitewidget_send_roles'] = $data['module_sitewidget_send_roles'] ?? 0;
        $data['module_sitewidget_send_email'] = $data['module_sitewidget_send_email'] ?? 1;
        $data['module_sitewidget_send_name'] = $data['module_sitewidget_send_name'] ?? 1;
        $data['sitewidget_switches'] = [
            'module_sitewidget_send_user_id' => $this->language->get('entry_send_user_id'),
            'module_sitewidget_send_roles' => $this->language->get('entry_send_roles'),
            'module_sitewidget_send_email' => $this->language->get('entry_send_email'),
            'module_sitewidget_send_name' => $this->language->get('entry_send_name'),
        ];
        $data['sitewidget_switch_help'] = [
            'module_sitewidget_send_roles' => $this->language->get('help_send_roles'),
        ];

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/sitewidget/module/sitewidget', $data));
    }

    public function install(): void
    {
        $this->load->model('setting/event');

        $this->model_setting_event->addEvent([
            'code' => 'sitewidget_footer',
            'description' => 'SiteWidget footer script injection',
            'trigger' => 'catalog/view/common/footer/after',
            'action' => 'extension/sitewidget/events.injectFooter',
            'status' => true,
            'sort_order' => 0,
        ]);
    }

    public function uninstall(): void
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('sitewidget_footer');
    }

    private function validate(): bool
    {
        return $this->user->hasPermission('modify', 'extension/sitewidget/module/sitewidget');
    }
}
