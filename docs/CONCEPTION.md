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

Recherche rapide (`*`) : `name`, `email`, `commercial_company_name`, `function` en
`ilike` ; modes strict / préfixe de Roundcube en `=ilike`.

Autocomplétion : les champs Odoo de `odoo_autocomplete_search` (`name`, `email`,
`function`) au lieu de `contactlist_fields`. Le hook `contacts_autocomplete_after`
ajoute un champ `details` (poste, société : `odoo_autocomplete_details`) aux
suggestions ; Elastic l'affiche sur une 3e ligne (`skins/elastic/roundcube_odoo.css`).
L'insertion utilise le nom du contact, pas ces champs.

Employés : le hook met `type = 'odoo-employee'` (classe de la suggestion → icône
bâtiment en CSS) et `odoo_logo` (URL) sur la suggestion, pas dans `fields` : sans le
script du plugin (autocomplétion d'un autre plugin), rien ne casse.
`roundcube_odoo.js` enveloppe `ksearch_query_results` et met le logo en fond de
l'icône de `#rcmkSearchItem<i>` (= `env.contacts[i]`). `odoo_employee_logo` :
`'odoo'` (logo de la société de l'employé, `res.company.logo_web`, ignoré si
`uses_default_logo`), fichier, URL, ou tableau par id / nom de société + `'*'`.
Les fichiers et logos Odoo passent par l'action `plugin.odoo-logo` (session requise,
cache 1 jour, CSP stricte pour les SVG). Sans contexte `allowed_company_ids`, l'API
voit toutes les sociétés de l'utilisateur technique.

Poste des employés : le `job_title` de `hr.employee.public` n'est pas recopié sur
le contact (`function` vide). Il est lu avec la liste des employés (type `employee`),
affiché quand `function` est vide, et la recherche sur `function` ajoute les
employés dont le poste correspond (`id in`).

## 6. Performance et robustesse

- Cache partagé `rcube_cache` (`odoo_cache`, `odoo_cache_ttl` = 5 min) de chaque appel.
- Timeouts 2 s / 4 s ; au premier échec, les appels suivants de la requête sont
  sautés, l'erreur est loguée, le carnet est vide.
- Pas de longueur minimale propre au plugin : le JS de Roundcube n'envoie plus les
  recherches qui prolongent une recherche sans résultat (« ma » vide → « marin » jamais
  envoyé). Seuil global possible avec `autocomplete_min_length`, que le JS respecte.
- Conseillé : `autocomplete_threads = 1`.
- Client HTTP de Roundcube (`get_http_client`) : respecte `http_client` (proxy, TLS).

## 7. Suite possible

- Clé API par utilisateur (droits Odoo de chacun).
- Étiquettes Odoo (`category_id`) comme groupes automatiques.
- « Ajouter à Odoo » depuis un message (création de `res.partner`).
