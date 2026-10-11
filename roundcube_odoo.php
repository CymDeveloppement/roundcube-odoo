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

    function addressbook_get($args)
    {
        if ($args['id'] === self::SOURCE) {
            $args['instance'] = $this->addressbook();
        }

        return $args;
    }

    private function addressbook()
    {
        if (!$this->abook) {
            $config = $this->rc->config;
            $client = new roundcube_odoo_client(array(
                'url' => $config->get('odoo_url'),
                'db' => $config->get('odoo_db'),
                'login' => $config->get('odoo_login'),
                'api_key' => $config->get('odoo_api_key'),
                'api' => $config->get('odoo_api'),
            ), $this->rc->get_http_client(array(
                'connect_timeout' => $config->get('odoo_connect_timeout'),
                'timeout' => $config->get('odoo_timeout'),
            )), $this->rc->get_cache_shared('odoo'));

            $this->abook = new roundcube_odoo_addressbook(
                $client,
                $config->get('odoo_addressbook_name'),
                $this->types(),
                (array) $config->get('odoo_domain'),
                (int) $config->get('odoo_min_length')
            );
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
