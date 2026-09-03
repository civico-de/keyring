(function (angular, $, _) {
  "use strict";

  // The contact tab: list, issue, revoke.
  //
  // Not a SearchKit display: the tab needs a per-row button whose accessible
  // name carries the token label, and a SearchKit table offers row actions
  // only through the bulk task menu.
  angular.module("keyringTokens").component("keyringTokens", {
    bindings: { contactId: "<" },
    templateUrl: "~/keyringTokens/keyringTokens.html",
    controller: function ($element, $timeout, crmApi4) {
      const ts = (this.ts = CRM.ts("keyring")),
        ctrl = this;

      this.tokens = [];
      this.profiles = [];
      this.loading = true;
      this.error = null;
      this.announcement = "";
      this.plaintext = null;
      this.showForm = false;
      this.form = { label: "", scopeProfile: "", mode: "report", expiresAt: "" };

      this.$onInit = function () {
        ctrl.reload();
        crmApi4("Setting", "get", { select: ["keyring_scope_profiles"] })
          .then(function (settings) {
            const raw = (settings[0] && settings[0].value) || "{}";
            ctrl.profiles = Object.keys(JSON.parse(raw));
            return ctrl.profiles;
          })
          .catch(function () {
            // A malformed profile setting must not hide the token list; the
            // profile picker is simply empty and the API says why on submit.
            ctrl.profiles = [];
          });
      };

      this.reload = function () {
        ctrl.loading = true;
        return crmApi4("KeyringToken", "get", {
          select: [
            "id",
            "label",
            "token_prefix",
            "scope",
            "scope_profile",
            "mode:label",
            "expires_at",
            "last_used_at",
            "revoked_at",
            "state",
            "state:label",
          ],
          where: [["contact_id", "=", ctrl.contactId]],
          orderBy: { created_date: "DESC" },
        }).then(
          function (tokens) {
            ctrl.tokens = tokens;
            ctrl.loading = false;
            return tokens;
          },
          function (failure) {
            ctrl.error = (failure && failure.error_message) || ts("Could not load tokens.");
            ctrl.loading = false;
          },
        );
      };

      // The state is the server's: it knows the site timezone and it is the
      // same verdict the authentication path applies.
      this.state = function (token) {
        return token.state;
      };

      this.stateLabel = function (token) {
        return token["state:label"];
      };

      // expires_at is a date stored as that day's 23:59:59; show the day.
      this.expiryDate = function (token) {
        return token.expires_at ? token.expires_at.substring(0, 10) : "";
      };

      this.scopeSummary = function (token) {
        if (token.scope_profile) {
          return token.scope_profile;
        }
        return ts("%1 permissions", { 1: (token.scope || []).length });
      };

      this.toggleForm = function () {
        ctrl.showForm = !ctrl.showForm;
        ctrl.error = null;
      };

      this.issue = function () {
        if (!ctrl.form.label || !ctrl.form.scopeProfile) {
          return;
        }
        ctrl.error = null;
        crmApi4("KeyringToken", "create", {
          contactId: ctrl.contactId,
          label: ctrl.form.label,
          scopeProfile: ctrl.form.scopeProfile,
          mode: ctrl.form.mode,
          expiresAt: ctrl.form.expiresAt || null,
        }).then(
          function (created) {
            ctrl.plaintext = created[0].token;
            ctrl.announcement = ts("Token created. Copy it now: it is not shown again.");
            ctrl.showForm = false;
            ctrl.form = { label: "", scopeProfile: "", mode: "report", expiresAt: "" };
            $timeout(function () {
              const block = $element[0].querySelector(".keyring-plaintext");
              if (block) {
                block.focus();
              }
            });
            return ctrl.reload();
          },
          function (failure) {
            ctrl.error = (failure && failure.error_message) || ts("Could not create the token.");
          },
        );
      };

      this.copy = function () {
        if (!ctrl.plaintext || !navigator.clipboard) {
          ctrl.announcement = ts("Copying is not available here; select the token and copy it by hand.");
          return;
        }
        navigator.clipboard.writeText(ctrl.plaintext).then(
          function () {
            $timeout(function () {
              ctrl.announcement = ts("Token copied to the clipboard.");
            });
          },
          function () {
            $timeout(function () {
              ctrl.announcement = ts("Copying failed; select the token and copy it by hand.");
            });
          },
        );
      };

      this.dismiss = function () {
        ctrl.plaintext = null;
        ctrl.announcement = ts("Token hidden.");
      };

      this.revoke = function (token) {
        // CRM.ts substitutes placeholders raw and CRM.confirm inserts the
        // message with .html(), so a label is markup unless it is escaped.
        CRM.confirm({
          title: ts("Revoke token"),
          message: ts('Revoke the token "%1"? Any client still using it stops working immediately.', {
            1: _.escape(token.label),
          }),
        }).on("crmConfirm:yes", function () {
          crmApi4("KeyringToken", "revoke", { where: [["id", "=", token.id]] }).then(
            function () {
              $timeout(function () {
                ctrl.announcement = ts('Token "%1" revoked.', { 1: token.label });
              });
              return ctrl.reload();
            },
            function (failure) {
              $timeout(function () {
                ctrl.error = (failure && failure.error_message) || ts("Could not revoke the token.");
              });
            },
          );
        });
      };
    },
  });
})(angular, CRM.$, CRM._);
