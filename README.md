# Roundcube Odoo

Read-only address book of the [Odoo](https://www.odoo.com) contacts for
[Roundcube](https://roundcube.net) webmail:

- the contacts are suggested in the **To / Cc / Bcc autocompletion**;
- an **Odoo** address book lists them in the Contacts screen, with a group for
  each contact type (customers, suppliers, employees...), and shows their
  details (email, phone, company, job position, address, notes).

Odoo is queried live, there is nothing to synchronize: a change in Odoo appears
after the cache lifetime (5 minutes by default).

Supports Odoo 18 and 19+, with the JSON-RPC API (`/jsonrpc`) or the JSON-2 API
(`/json/2`, Odoo 19 and later), chosen automatically.

## Requirements

- Roundcube 1.6 or later (tested with 1.6.19 and 1.7.4)
- PHP 7.3 or later with the json extension
- Odoo 18 or later (tested with 19, JSON-RPC and JSON-2 APIs)

## Installation

```bash
composer require cymdeveloppement/roundcube-odoo
```

or copy the folder to `plugins/roundcube_odoo` and add `roundcube_odoo` to
`$config['plugins']`. Then copy `config.inc.php.dist` to `config.inc.php`.

## Odoo setup

1. Create an internal user for Roundcube, e.g. `roundcube`. The address book
   lists the contacts this user can read.
2. Log in as this user, **My Profile > Account Security > New API Key**, and copy
   the key. Give it no expiration date (an administrator can, from the user form).
3. The `customer` and `supplier` types need the Invoicing app, `employee` the
   Employees app. The employees are read from `hr.employee.public`, readable by
   all internal users: no HR rights are needed.

## Configuration

```php
$config['odoo_url'] = 'https://erp.example.com';
$config['odoo_db'] = 'production';
$config['odoo_login'] = 'roundcube';
$config['odoo_api_key'] = '...';

// Listed contacts: customers, suppliers and employees, one group each
$config['odoo_types'] = ['customer', 'supplier', 'employee'];
```

| Option | Default | |
|---|---|---|
| `odoo_api` | `'auto'` | `'json2'` (Odoo 19+), `'jsonrpc'` (Odoo 18 and 19, removed in Odoo 22) |
| `odoo_addressbook_name` | `'Odoo'` | Name in the Contacts screen |
| `odoo_types` | customer, supplier, employee | Listed types, see below |
| `odoo_domain` | `[]` | Extra Odoo domain applied to all the contacts |
| `odoo_autocomplete` | `true` | Suggest the contacts in the autocompletion |
| `odoo_min_length` | `3` | Minimum length of a search sent to Odoo |
| `odoo_cache` / `odoo_cache_ttl` | `'db'` / `'5m'` | Cache shared by all users |
| `odoo_connect_timeout` / `odoo_timeout` | `2` / `4` | Seconds |

### Contact types

A contact is listed when it matches one of the types of `odoo_types`, and each
type is a group of the address book. An empty list shows all the contacts,
without groups. Contacts without email are never listed.

| Type | Contacts |
|---|---|
| `customer` | Customers and the contacts of customer companies |
| `supplier` | Suppliers and the contacts of supplier companies |
| `employee` | Work contacts of the employees |
| `company` | Companies |
| `person` | Individuals |

Custom types are Odoo domains:

```php
$config['odoo_types'] = [
    'customer',
    'vip' => ['name' => 'VIP', 'domain' => [['category_id.name', '=', 'VIP']]],
];
```

The groups are not proposed in the autocompletion: selecting one would add all
its contacts as recipients.

### Performance

Each search is an HTTP request to Odoo. The results are cached for all users,
and the timeouts are short so that a slow Odoo does not block the typing; when
Odoo fails, the address book is empty and the error is logged. To query the
other address books without waiting for Odoo:

```php
$config['autocomplete_threads'] = 1;
```

## License

MIT, see [LICENSE](LICENSE).
