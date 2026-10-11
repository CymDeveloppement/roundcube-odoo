<?php

/**
 * Read-only address book of the Odoo contacts (res.partner)
 *
 * Every listing or search is an Odoo query. The contacts of the configured
 * types are listed, each type being a group of the address book.
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 */
class roundcube_odoo_addressbook extends rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly = true;
    public $groups = false;
    public $coltypes = array('name', 'email', 'phone', 'address', 'organization', 'jobtitle', 'website', 'notes');

    /** Odoo fields searched for each Roundcube field */
    const SEARCH_FIELDS = array(
        '*' => array('name', 'email', 'commercial_company_name', 'function'),
        'name' => array('name'),
        'firstname' => array('name'),
        'surname' => array('name'),
        'email' => array('email'),
        'organization' => array('commercial_company_name'),
        'jobtitle' => array('function'),
        'phone' => array('phone'),
        'address' => array('street', 'city', 'zip'),
        'street' => array('street'),
        'locality' => array('city'),
        'zipcode' => array('zip'),
        'website' => array('website'),
        'notes' => array('comment'),
    );

    /** Separator of the contact ids of a search by id */
    const SEPARATOR = ',';

    /** Domain matching no record */
    const NONE = array(array('id', '=', 0));

    /** @var roundcube_odoo_client */
    private $client;

    /** @var string */
    private $name;

    /** @var array Contact types: key => ['name' => label, 'domain' => domain or 'employee' => true] */
    private $types;

    /** @var array Domain set by the configuration */
    private $domain;

    /** @var ?array Domain of the current search */
    private $filter;

    /** @var ?rcube_result_set */
    private $result;

    /** @var bool True once a call failed: the next ones are skipped */
    private $offline = false;

    /** @var ?array Odoo fields searched by the autocompletion */
    private $autocomplete_fields;

    /** @var array Records read during the request, by id */
    private $records = array();

    /** @var ?array Employees by work contact id: ['job' => job position, 'company' => [id, name] or false] */
    private $employees;

    /**
     * @param roundcube_odoo_client $client
     * @param string $name       Name of the address book
     * @param array  $types      Contact types, see $types
     * @param array  $domain     Domain set by the configuration
     */
    public function __construct($client, $name, array $types, array $domain)
    {
        $this->client = $client;
        $this->name = $name;
        $this->types = $types;
        $this->domain = $domain;
        $this->groups = !empty($types);
        $this->ready = true;
    }

    /**
     * Searches the given Odoo fields instead of the Roundcube ones, for the
     * autocompletion
     */
    public function set_autocomplete_fields(array $fields)
    {
        $this->autocomplete_fields = $fields;
    }

    /**
     * Record already read during the request, null if unknown
     */
    public function known_record($id)
    {
        return $this->records[$id] ?? null;
    }

    #[\Override]
    public function get_name()
    {
        return $this->name;
    }

    #[\Override]
    public function set_search_set($filter): void
    {
        $this->filter = $filter;
    }

    #[\Override]
    public function get_search_set()
    {
        return $this->filter;
    }

    #[\Override]
    public function reset(): void
    {
        $this->result = null;
        $this->filter = null;
    }

    #[\Override]
    public function get_result()
    {
        return $this->result;
    }

    #[\Override]
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $domain = $this->domain();
        $first = ($this->list_page - 1) * $this->page_size;
        $offset = $first;
        $limit = $this->page_size;

        if ($subset > 0) {
            $limit = min($subset, $limit);
        } else if ($subset < 0) {
            $offset += $limit + $subset;
            $limit = -$subset;
        }

        $rows = $this->query('res.partner', 'search_read', array(
            'domain' => $domain,
            'fields' => $this->fields(),
            'offset' => max(0, $offset),
            'limit' => $limit,
            'order' => 'name ' . ($this->sort_order == 'DESC' ? 'desc' : 'asc') . ', id',
        ), array());

        $count = $nocount ? $first + count($rows) : $this->query('res.partner', 'search_count', array('domain' => $domain), 0);
        $this->result = new rcube_result_set($count, $first);

        foreach ($rows as $row) {
            $this->result->add($this->record($row));
        }

        return $this->result;
    }

    #[\Override]
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = array())
    {
        if ($fields == 'ID' || $fields == $this->primary_key) {
            $ids = is_array($value) ? $value : explode(self::SEPARATOR, (string) $value);
            $filter = array(array('id', 'in', array_map('intval', $ids)));
        } else if (is_array($value)) {
            // Advanced search: every field must match
            $conditions = array();
            foreach ((array) $fields as $i => $field) {
                if (isset($value[$i]) && strlen(trim($value[$i]))) {
                    $conditions[] = $this->condition($field, trim($value[$i]), $mode);
                }
            }
            $filter = $conditions ? self::and_domains($conditions) : self::NONE;
        } else {
            // No minimum length: the autocompletion stops searching once a
            // search finds nothing, a longer one would never be sent
            $filter = $this->autocomplete_fields
                ? $this->odoo_condition($this->autocomplete_fields, trim((string) $value), $mode)
                : $this->condition($fields, trim((string) $value), $mode);
        }

        foreach ((array) $required as $field) {
            if ($field != 'email' && ($names = $this->odoo_fields($field))) {
                $filter = self::and_domains(array($filter, self::or_domains(array_map(function ($name) {
                    return array(array($name, '!=', false));
                }, $names))));
            }
        }

        $this->filter = $filter;

        if ($select) {
            return $this->list_records(null, 0, $nocount);
        }

        return $this->result = $this->count();
    }

    #[\Override]
    public function count()
    {
        $count = $this->query('res.partner', 'search_count', array('domain' => $this->domain()), 0);

        return new rcube_result_set($count, ($this->list_page - 1) * $this->page_size);
    }

    #[\Override]
    public function get_record($id, $assoc = false)
    {
        $rows = $this->query('res.partner', 'search_read', array(
            'domain' => $this->domain(array(array('id', '=', (int) $id))),
            'fields' => $this->fields(),
            'limit' => 1,
        ), array());

        $record = $rows ? $this->record($rows[0]) : null;

        if ($assoc) {
            return $record;
        }

        $this->result = new rcube_result_set($record ? 1 : 0);
        if ($record) {
            $this->result->add($record);
        }

        return $this->result;
    }

    #[\Override]
    public function list_groups($search = null, $mode = 0)
    {
        // A search comes from the autocompletion: a whole type is not a
        // suitable recipient
        if (!$this->groups || ($search !== null && strlen($search))) {
            return array();
        }

        $groups = array();
        foreach ($this->types as $key => $type) {
            $groups[] = array('ID' => $key, 'name' => $type['name']);
        }

        return $groups;
    }

    #[\Override]
    public function get_group($group_id)
    {
        if (!isset($this->types[$group_id])) {
            return null;
        }

        return array('ID' => $group_id, 'name' => $this->types[$group_id]['name']);
    }

    #[\Override]
    public function set_group($group_id)
    {
        $this->group_id = isset($this->types[$group_id]) ? $group_id : null;
    }

    #[\Override]
    public function get_record_groups($id)
    {
        $groups = array();

        foreach ($this->types as $key => $type) {
            $domain = self::and_domains(array($this->type_domain($key), array(array('id', '=', (int) $id))));
            if ($this->query('res.partner', 'search_count', array('domain' => $domain), 0)) {
                $groups[$key] = $type['name'];
            }
        }

        return $groups;
    }

    /**
     * Domain of the listed contacts: configuration, selected group or
     * configured types, current search, and $extra
     */
    private function domain(array $extra = array())
    {
        $domains = array(array(array('email', '!=', false)), $this->domain);

        if ($this->group_id) {
            $domains[] = $this->type_domain($this->group_id);
        } else if ($this->types) {
            $domains[] = self::or_domains(array_map(array($this, 'type_domain'), array_keys($this->types)));
        }

        if ($this->filter) {
            $domains[] = $this->filter;
        }

        $domains[] = $extra;

        return self::and_domains($domains);
    }

    /**
     * Domain of the contacts of a type
     */
    private function type_domain($key)
    {
        $type = $this->types[$key];

        if (empty($type['employee'])) {
            return $type['domain'];
        }

        $ids = array_keys($this->employees());

        return $ids ? array(array('id', 'in', $ids)) : self::NONE;
    }

    /**
     * Employee of a work contact, see $employees, null if not an employee
     */
    public function employee($id)
    {
        return $this->employees()[$id] ?? null;
    }

    /**
     * Employees by work contact id, see $employees. Empty without the
     * 'employee' type, the Employees app may not be installed.
     */
    private function employees()
    {
        if ($this->employees === null) {
            $this->employees = array();

            if (!in_array(true, array_column($this->types, 'employee'))) {
                return $this->employees;
            }

            // The employees are read from hr.employee.public, readable by all
            // internal users, unlike res.partner.employee_ids. Their job
            // position is not copied to their work contact.
            $rows = $this->query('hr.employee.public', 'search_read', array(
                'domain' => array(array('work_contact_id', '!=', false)),
                'fields' => array('work_contact_id', 'job_title', 'company_id'),
            ), array());

            foreach ($rows as $row) {
                $this->employees[$row['work_contact_id'][0]] = array(
                    'job' => (string) $row['job_title'],
                    'company' => $row['company_id'],
                );
            }
        }

        return $this->employees;
    }

    /**
     * Domain matching $value in one of the Odoo fields of the Roundcube $fields
     */
    private function condition($fields, $value, $mode)
    {
        $names = array();
        foreach ((array) $fields as $field) {
            $names = array_merge($names, $this->odoo_fields($field));
        }

        return $this->odoo_condition($names, $value, $mode);
    }

    /**
     * Domain matching $value in one of the Odoo fields $names
     */
    private function odoo_condition(array $names, $value, $mode)
    {
        if (!$names) {
            return self::NONE;
        }

        $raw = $value;
        $mode &= ~self::SEARCH_GROUPS;
        if ($mode == self::SEARCH_STRICT || $mode == self::SEARCH_PREFIX) {
            $operator = '=ilike';
            $value = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
            if ($mode == self::SEARCH_PREFIX) {
                $value .= '%';
            }
        } else {
            $operator = 'ilike';
        }

        $domains = array();
        foreach (array_unique($names) as $name) {
            $domains[] = array(array($name, $operator, $value));
        }

        if (in_array('function', $names) && ($ids = $this->employees_by_job($raw, $mode))) {
            $domains[] = array(array('id', 'in', $ids));
        }

        return self::or_domains($domains);
    }

    /**
     * Work contacts of the employees whose job position matches $value
     */
    private function employees_by_job($value, $mode)
    {
        $ids = array();
        foreach ($this->employees() as $id => $employee) {
            $job = $employee['job'];
            $pos = $job === '' ? false : mb_stripos($job, $value);
            if ($mode == self::SEARCH_STRICT ? mb_strtolower($job) === mb_strtolower($value)
                : ($mode == self::SEARCH_PREFIX ? $pos === 0 : $pos !== false)
            ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Odoo fields searched for a Roundcube field
     */
    private function odoo_fields($field)
    {
        $field = explode(':', $field)[0];
        $names = self::SEARCH_FIELDS[$field] ?? array();

        if ($field == 'phone' && $this->has_mobile()) {
            $names[] = 'mobile';
        }

        return $names;
    }

    /**
     * Fields read for the records
     */
    private function fields()
    {
        $fields = array('name', 'email', 'is_company', 'commercial_company_name', 'function', 'phone',
            'street', 'street2', 'zip', 'city', 'state_id', 'country_id', 'website', 'comment');

        if ($this->has_mobile()) {
            $fields[] = 'mobile';
        }

        return $fields;
    }

    /**
     * True if res.partner has a mobile field (removed in Odoo 19)
     */
    private function has_mobile()
    {
        if ($this->offline) {
            return false;
        }

        try {
            return $this->client->version() < 19;
        } catch (Exception $e) {
            $this->failed($e);

            return false;
        }
    }

    /**
     * Converts a res.partner into a Roundcube contact
     */
    private function record(array $row)
    {
        $record = array(
            'ID' => $row['id'],
            'name' => (string) $row['name'],
            'email:work' => array_values(array_filter(preg_split('/[\s,;]+/', (string) $row['email']), function ($email) {
                return strpos($email, '@') !== false;
            })),
        );

        if (!$row['is_company'] && $row['commercial_company_name'] && $row['commercial_company_name'] != $row['name']) {
            $record['organization'] = $row['commercial_company_name'];
        }
        if ($row['function']) {
            $record['jobtitle'] = $row['function'];
        } else if (!empty($this->employees()[$row['id']]['job'])) {
            $record['jobtitle'] = $this->employees()[$row['id']]['job'];
        }
        if ($row['phone']) {
            $record['phone:work'] = array($row['phone']);
        }
        if (!empty($row['mobile'])) {
            $record['phone:mobile'] = array($row['mobile']);
        }
        if ($row['street'] || $row['street2'] || $row['zip'] || $row['city']) {
            $record['address:work'] = array(array(
                'street' => trim($row['street'] . "\n" . $row['street2']),
                'locality' => (string) $row['city'],
                'zipcode' => (string) $row['zip'],
                'region' => $row['state_id'] ? $row['state_id'][1] : '',
                'country' => $row['country_id'] ? $row['country_id'][1] : '',
            ));
        }
        if ($row['website']) {
            $record['website:work'] = array($row['website']);
        }
        if ($row['comment']) {
            // HTML field
            $notes = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $row['comment'])), ENT_QUOTES, 'UTF-8'));
            if ($notes !== '') {
                $record['notes'] = $notes;
            }
        }

        return $this->records[$record['ID']] = $record;
    }

    /**
     * Calls Odoo, returns $default on error
     */
    private function query($model, $method, array $kwargs, $default)
    {
        if ($this->offline) {
            return $default;
        }

        try {
            return $this->client->cached_call($model, $method, $kwargs);
        } catch (Exception $e) {
            $this->failed($e);

            return $default;
        }
    }

    /**
     * Logs an error, the next calls of the request are skipped
     */
    private function failed(Exception $e)
    {
        $this->offline = true;
        $this->set_error(self::ERROR_NO_CONNECTION, 'roundcube_odoo.connection_error');

        rcube::raise_error(array(
            'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
            'message' => $e->getMessage(),
        ), true, false);
    }

    /**
     * Domain matching all the given domains. Empty domains match everything.
     */
    public static function and_domains(array $domains)
    {
        return self::combine('&', $domains);
    }

    /**
     * Domain matching one of the given domains
     */
    public static function or_domains(array $domains)
    {
        foreach ($domains as $domain) {
            if (!$domain) {
                return array();
            }
        }

        return self::combine('|', $domains);
    }

    private static function combine($operator, array $domains)
    {
        $domains = array_values(array_filter($domains));
        if (count($domains) < 2) {
            return $domains ? $domains[0] : array();
        }

        $result = array_fill(0, count($domains) - 1, $operator);
        foreach ($domains as $domain) {
            $result = array_merge($result, self::normalize($domain));
        }

        return $result;
    }

    /**
     * Adds the implicit '&' operators of a domain (port of Odoo's normalize_domain)
     */
    public static function normalize(array $domain)
    {
        $result = array();
        $expected = 1;

        foreach ($domain as $token) {
            if ($expected == 0) {
                array_unshift($result, '&');
                $expected = 1;
            }
            if (is_array($token)) {
                $expected--;
            } else {
                $expected += $token == '!' ? 0 : 1;
            }
            $result[] = $token;
        }

        return $result;
    }
}
