<?php

require_once __DIR__ . '/lib/roundcube_odoo_client.php';
require_once __DIR__ . '/lib/roundcube_odoo_addressbook.php';

/**
 * Roundcube Odoo
 *
 * Read-only address book of the Odoo contacts: they are suggested in the
 * recipient autocompletion and listed in the Contacts screen, with a group
 * for each contact type (customers, suppliers, employees...).
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 * @category  Plugin for RoundCube WebMail
 */
class roundcube_odoo extends rcube_plugin
{
    public $task = 'mail|addressbook';

    /** Id of the address book */
    const SOURCE = 'odoo';

    /** Built-in contact types: domain, or employees of hr.employee.public */
    const TYPES = array(
        'customer' => array('label' => 'customers', 'domain' => array(array('commercial_partner_id.customer_rank', '>', 0))),
        'supplier' => array('label' => 'suppliers', 'domain' => array(array('commercial_partner_id.supplier_rank', '>', 0))),
        'employee' => array('label' => 'employees', 'employee' => true),
        'company' => array('label' => 'companies', 'domain' => array(array('is_company', '=', true))),
        'person' => array('label' => 'persons', 'domain' => array(array('is_company', '=', false))),
    );

    /** @var rcmail */
    private $rc;

    /** @var ?roundcube_odoo_addressbook */
    private $abook;

    /** @var ?roundcube_odoo_client */
    private $client;

    /** @var ?array Companies of Odoo: id => ['name' => name, 'logo' => true if not the default logo] */
    private $companies;

    function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config('config.inc.php.dist');
        $this->load_config('config.inc.php');

        if (!$this->is_enabled()) {
            return;
        }

        $this->add_hook('addressbooks_list', array($this, 'addressbooks_list'));
        $this->add_hook('addressbook_get', array($this, 'addressbook_get'));

        if ($this->rc->config->get('odoo_autocomplete')) {
            $sources = (array) $this->rc->config->get('autocomplete_addressbooks', array('sql'));
            if (!in_array(self::SOURCE, $sources)) {
                $sources[] = self::SOURCE;
                $this->rc->config->set('autocomplete_addressbooks', $sources);
            }

            $this->add_hook('contacts_autocomplete_after', array($this, 'contacts_autocomplete_after'));

            if ($this->rc->task == 'mail') {
                $stylesheet = $this->local_skin_path() . '/roundcube_odoo.css';
                if (is_file($this->home . '/' . $stylesheet)) {
                    $this->include_stylesheet($stylesheet);
                }
                $this->include_script('roundcube_odoo.js');
                $this->register_action('plugin.odoo-logo', array($this, 'logo_action'));
            }
        }
    }

    function addressbooks_list($args)
    {
        $args['sources'][self::SOURCE] = array(
            'id' => self::SOURCE,
            'name' => $this->addressbook()->get_name(),
            'readonly' => true,
            'groups' => $this->addressbook()->groups,
        );

        return $args;
    }

    /**
     * Adds the details of the Odoo contacts (job position...) to the
     * suggestions, on a line of their own in Elastic, and marks the employees
     * with their logo (see roundcube_odoo.js)
     */
    function contacts_autocomplete_after($args)
    {
        $keys = (array) $this->rc->config->get('odoo_autocomplete_details');

        foreach ($args['contacts'] as $i => $contact) {
            if (($contact['source'] ?? null) !== self::SOURCE || !$this->abook
                || !($record = $this->abook->known_record($contact['id']))
            ) {
                continue;
            }

            $details = array();
            foreach ($keys as $key) {
                if (!empty($record[$key]) && is_string($record[$key])) {
                    $details[] = $record[$key];
                }
            }

            if ($details) {
                $args['contacts'][$i]['fields']['details'] = implode(' · ', $details);
            }

            if ($employee = $this->abook->employee($contact['id'])) {
                $args['contacts'][$i]['type'] = 'odoo-employee';
                if ($logo = $this->logo_url($employee['company'])) {
                    $args['contacts'][$i]['odoo_logo'] = $logo;
                }
            }
        }

        return $args;
    }

    /**
     * Sends the logo of a company, for the employees in the suggestions
     */
    function logo_action()
    {
        $id = (int) rcube_utils::get_input_string('_company', rcube_utils::INPUT_GET);
        $source = $this->logo_source($id);
        $data = null;

        if ($source === 'odoo') {
            try {
                $rows = $this->client()->cached_call('res.company', 'search_read', array(
                    'domain' => array(array('id', '=', $id)),
                    'fields' => array('logo_web'),
                ));
                $data = $rows && $rows[0]['logo_web'] ? base64_decode($rows[0]['logo_web']) : null;
            } catch (Exception $e) {
                rcube::raise_error(array(
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => $e->getMessage(),
                ), true, false);
            }
        } else if ($source && !self::is_url($source)) {
            $path = $source[0] == '/' ? $source : $this->home . '/' . $source;
            $data = is_file($path) ? file_get_contents($path) : null;
        }

        if (!$data) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        if (preg_match('/^\s*(<\?xml[^>]*>\s*)?<svg/i', $data)) {
            $type = 'image/svg+xml';
        } else if (substr($data, 0, 4) == 'RIFF' && substr($data, 8, 4) == 'WEBP') {
            $type = 'image/webp';
        } else {
            $type = rcube_mime::image_content_type($data);
        }

        header('Content-Type: ' . $type);
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        echo $data;
        exit;
    }

    /**
     * URL of the logo of the employees of a company, null for the icon
     *
     * @param array|false $company [id, name] of the company
     */
    private function logo_url($company)
    {
        $id = $company ? (int) $company[0] : 0;
        $source = $this->logo_source($id);

        if (!$source) {
            return null;
        }
        if (self::is_url($source)) {
            return $source;
        }

        return $this->rc->url(array('_action' => 'plugin.odoo-logo', '_company' => $id));
    }

    /**
     * Logo configured for the employees of a company: 'odoo', a file or a
     * URL, null for the icon
     */
    private function logo_source($id)
    {
        $config = $this->rc->config->get('odoo_employee_logo');

        if (is_array($config)) {
            $name = $this->companies()[$id]['name'] ?? null;
            $source = $config[$id] ?? ($name !== null ? $config[$name] ?? null : null) ?? $config['*'] ?? null;
        } else {
            $source = $config;
        }

        if ($source === 'odoo' && empty($this->companies()[$id]['logo'])) {
            return null;
        }

        return $source ?: null;
    }

    /**
     * Companies of Odoo, see $companies
     */
    private function companies()
    {
        if ($this->companies === null) {
            $this->companies = array();

            try {
                $rows = $this->client()->cached_call('res.company', 'search_read', array(
                    'fields' => array('name', 'uses_default_logo'),
                ));
            } catch (Exception $e) {
                $rows = array();
                rcube::raise_error(array(
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => $e->getMessage(),
                ), true, false);
            }

            foreach ($rows as $row) {
                $this->companies[$row['id']] = array('name' => $row['name'], 'logo' => !$row['uses_default_logo']);
            }
        }

        return $this->companies;
    }

    private static function is_url($source)
    {
        return (bool) preg_match('#^https?://#i', $source);
    }

    function addressbook_get($args)
    {
        if ($args['id'] === self::SOURCE) {
            $args['instance'] = $this->addressbook();
        }

        return $args;
    }

    private function client()
    {
        if (!$this->client) {
            $config = $this->rc->config;
            $this->client = new roundcube_odoo_client(array(
                'url' => $config->get('odoo_url'),
                'db' => $config->get('odoo_db'),
                'login' => $config->get('odoo_login'),
                'api_key' => $config->get('odoo_api_key'),
                'api' => $config->get('odoo_api'),
            ), $this->rc->get_http_client(array(
                'connect_timeout' => $config->get('odoo_connect_timeout'),
                'timeout' => $config->get('odoo_timeout'),
            )), $this->rc->get_cache_shared('odoo'));
        }

        return $this->client;
    }

    private function addressbook()
    {
        if (!$this->abook) {
            $config = $this->rc->config;
            $this->abook = new roundcube_odoo_addressbook(
                $this->client(),
                $config->get('odoo_addressbook_name'),
                $this->types(),
                (array) $config->get('odoo_domain')
            );

            if ($this->rc->action == 'autocomplete' && ($fields = (array) $config->get('odoo_autocomplete_search'))) {
                $this->abook->set_autocomplete_fields($fields);
            }
        }

        return $this->abook;
    }

    /**
     * Configured contact types, built-in or custom
     */
    private function types()
    {
        $this->add_texts('localization/');

        $types = array();
        foreach ((array) $this->rc->config->get('odoo_types') as $key => $type) {
            if (is_string($type) && isset(self::TYPES[$type])) {
                $types[$type] = self::TYPES[$type] + array('name' => $this->gettext(self::TYPES[$type]['label']));
            } else if (is_array($type) && !empty($type['domain'])) {
                $types[$key] = array('name' => $type['name'] ?? $key, 'domain' => $type['domain']);
            } else {
                rcube::raise_error(array(
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "roundcube_odoo: invalid contact type " . (is_string($type) ? $type : $key),
                ), true, false);
            }
        }

        return $types;
    }

    private function is_enabled()
    {
        $config = $this->rc->config;

        return $config->get('odoo_url') && $config->get('odoo_db') && $config->get('odoo_login') && $config->get('odoo_api_key');
    }
}
