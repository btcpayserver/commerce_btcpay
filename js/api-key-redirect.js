(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.btcpayApiKeyRedirect = {
    attach(context) {
      once('btcpay-api-key', '.btcpay-generate-api-key', context).forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();

          const settings = drupalSettings.commerce_btcpay || {};
          const serverUrlField = document.querySelector('[name*="[server_url]"]');
          const serverUrl = serverUrlField ? serverUrlField.value.trim() : '';

          if (!isSecureUrl(serverUrl, Boolean(settings.allow_insecure_http))) {
            window.alert(Drupal.t('Enter a valid HTTPS BTCPay Server URL.'));
            return;
          }
          if (!settings.gateway_id || !settings.authorize_url) {
            window.alert(Drupal.t('Save the payment gateway before generating an API key.'));
            return;
          }

          button.disabled = true;
          try {
            const response = await fetch(settings.authorize_url, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                gateway_id: settings.gateway_id,
                server_url: serverUrl
              })
            });
            const result = await response.json();
            if (!response.ok || !result.authorization_url) {
              throw new Error(result.message || Drupal.t('Could not start BTCPay authorization.'));
            }
            window.location.assign(result.authorization_url);
          }
          catch (error) {
            window.alert(error.message || Drupal.t('Could not start BTCPay authorization.'));
            button.disabled = false;
          }
        });
      });

      function isSecureUrl(serverUrl, allowInsecureHttp) {
        try {
          const url = new URL(serverUrl);
          const allowedProtocol = url.protocol === 'https:' || (allowInsecureHttp && url.protocol === 'http:');
          return allowedProtocol && !url.username && !url.password && !url.search && !url.hash;
        }
        catch (error) {
          return false;
        }
      }
    }
  };

})(Drupal, drupalSettings, once);
