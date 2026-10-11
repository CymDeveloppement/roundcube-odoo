/**
 * Roundcube Odoo
 *
 * Replaces the icon of the employees by the logo of their company in the
 * recipient suggestions (odoo_logo set by contacts_autocomplete_after).
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 */
if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        var query_results = rcmail.ksearch_query_results;

        rcmail.ksearch_query_results = function () {
            query_results.apply(this, arguments);

            if (!this.ksearch_pane) {
                return;
            }

            // The suggestion of env.contacts[i] is #rcmkSearchItem<i>
            var pane = this.ksearch_pane;
            $.each(this.env.contacts || [], function (i, contact) {
                if (contact && contact.odoo_logo) {
                    pane.find('#rcmkSearchItem' + i).children('i.icon').addClass('odoo-logo')
                        .css('background-image', 'url("' + String(contact.odoo_logo).replace(/["\\\n]/g, encodeURIComponent) + '")');
                }
            });
        };
    });
}
