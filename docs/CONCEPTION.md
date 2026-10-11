# roundcube_odoo — conception

Objectif : proposer les contacts Odoo (`res.partner`) dans l'autocomplétion des
champs À / Cc / Cci, et les afficher dans un carnet d'adresses « Odoo » en lecture
seule, filtré par types (clients, fournisseurs, employés). Odoo 18 et 19+.

## 1. Synchroniser ou interroger en direct ?

| | A. Carnet d'adresses « live » (retenu) | B. Synchronisation dans le carnet SQL |
|---|---|---|
| Principe | Classe `rcube_addressbook` qui interroge Odoo à chaque recherche | Cron `bin/sync.php` qui copie les partenaires dans le carnet `sql` de chaque utilisateur |
| Fraîcheur | Immédiate | Dépend de la fréquence du cron |
| Complexité | Faible : pas de conflits, pas de suppressions à gérer | Forte : doublons avec les contacts perso, suppressions, modifications locales, N utilisateurs × M partenaires |
| Odoo indisponible | Pas de suggestions Odoo (le carnet perso marche toujours) | Fonctionne hors ligne |
| Performances | 1 requête HTTP par frappe → cache + timeout courts | Requêtes locales |

**Décision : A.** C'est le mécanisme prévu par Roundcube (même principe que les
carnets LDAP, cf. `plugins/example_addressbook`). Une source ajoutée à
`autocomplete_addressbooks` est interrogée automatiquement par
`program/actions/mail/autocomplete.php` : aucun JS à écrire.
La synchronisation (B) reste possible plus tard si le besoin hors ligne apparaît.

## 2. Branchement dans Roundcube

```
roundcube_odoo.php            (rcube_plugin)
  hook addressbooks_list  → déclare la source 'odoo' (readonly, groups selon config)
  hook addressbook_get    → instancie roundcube_odoo_addressbook
  init()                  → ajoute 'odoo' à autocomplete_addressbooks
lib/roundcube_odoo_addressbook.php  (extends rcube_addressbook)
  search()        → autocomplétion + recherche dans l'écran Contacts
  list_records()  → liste paginée (offset/limit + search_count)
  get_record()    → fiche d'un contact
  list_groups()/get_group()/set_group() → types de contacts
lib/roundcube_odoo_client.php       (client HTTP, sans dépendance)
```

Le plugin tourne sur les tâches `mail` (autocomplétion) et `addressbook` (écran Contacts).

## 3. API Odoo (18 et 19+)

`odoo_api = 'auto'` : `GET /json/version` répond à partir d'Odoo 19 → **JSON-2**
(`POST /json/2/<modèle>/<méthode>`, `Authorization: bearer <clé>`, `X-Odoo-Database`).
Sinon **JSON-RPC** (`/jsonrpc`, `execute_kw` avec l'`uid` obtenu par `common.authenticate`),
déprécié en 19 et supprimé en 22. La version et l'`uid` sont mis en cache.

Authentification : **utilisateur technique** interne + clé API sans expiration.
Tous les utilisateurs Roundcube voient les mêmes contacts.

## 4. Types de contacts

`odoo_types` liste les types affichés ; un contact est listé s'il correspond à l'un
d'eux, et chaque type est un **groupe** du carnet (Clients, Fournisseurs, Employés).

| Type | Domaine |
|---|---|
| `customer` | `commercial_partner_id.customer_rank > 0` : clients **et leurs contacts** |
| `supplier` | `commercial_partner_id.supplier_rank > 0` |
| `employee` | `id in` les `work_contact_id` de `hr.employee.public` (lisible par tout utilisateur interne, contrairement à `res.partner.employee_ids` réservé aux officiers RH) |
| `company` / `person` | `is_company` |
| personnalisé | `'clé' => ['name' => ..., 'domain' => [...]]` |

Les groupes ne sont pas proposés à l'autocomplétion (un clic ajouterait tout le type).

## 5. Correspondance des champs

| Roundcube | res.partner |
|---|---|
| `name` | `name` |
| `email:work` | `email`, découpé sur `,` `;` et espaces |
| `organization` | `commercial_company_name` (pour les personnes) |
| `jobtitle` | `function` |
| `phone:work` / `phone:mobile` | `phone` / `mobile` (Odoo 18 seulement, supprimé en 19) |
| `address:work` | `street`, `street2`, `zip`, `city`, `state_id`, `country_id` |
| `website:work`, `notes` | `website`, `comment` (HTML → texte) |

Recherche rapide (`*`) : `name`, `email`, `commercial_company_name` en `ilike` ;
modes strict / préfixe de Roundcube en `=ilike`.

## 6. Performance et robustesse

- Cache partagé `rcube_cache` (`odoo_cache`, `odoo_cache_ttl` = 5 min) de chaque appel.
- Timeouts 2 s / 4 s ; au premier échec, les appels suivants de la requête sont
  sautés, l'erreur est loguée, le carnet est vide.
- `odoo_min_length` = 3 ; conseillé : `autocomplete_threads = 1`.
- Client HTTP de Roundcube (`get_http_client`) : respecte `http_client` (proxy, TLS).

## 7. Suite possible

- Clé API par utilisateur (droits Odoo de chacun).
- Étiquettes Odoo (`category_id`) comme groupes automatiques.
- « Ajouter à Odoo » depuis un message (création de `res.partner`).
