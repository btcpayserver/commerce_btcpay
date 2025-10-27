(function (Drupal, drupalSettings, once) {
  'use strict';

  // Required permissions for BTCPay API key
  const REQUIRED_PERMISSIONS = [
    'btcpay.store.canviewinvoices',
    'btcpay.store.cancreateinvoice',
    'btcpay.store.canviewstoresettings',
    'btcpay.store.canmodifyinvoices',
    'btcpay.store.webhooks.canmodifywebhooks'
  ];

  Drupal.behaviors.btcpayApiKeyRedirect = {
    attach: function (context, settings) {
      const buttons = once('btcpay-api-key', '.btcpay-generate-api-key', context);
      
      buttons.forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();

          console.log('BTCPay Generate API Key button clicked');

          const serverUrlField = document.querySelector('[name*="[server_url]"]');
          const serverUrl = serverUrlField ? serverUrlField.value.trim() : '';

          console.log('Server URL:', serverUrl);

          if (!isValidUrl(serverUrl)) {
            alert(Drupal.t('Please enter a valid URL including https:// in the BTCPay Server URL field.'));
            return;
          }

          // Generate a unique token
          const token = 'btcpay_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15);
          
          // Get the gateway ID from drupalSettings (passed from PHP)
          const gatewayId = drupalSettings.commerce_btcpay?.gateway_id || '';
          
          console.log('Gateway ID:', gatewayId);
          
          if (!gatewayId) {
            alert(Drupal.t('Could not determine payment gateway ID. Please save the gateway first.'));
            return;
          }
          
          // Build the callback URL with token and gateway_id
          const callbackUrl = window.location.origin + drupalSettings.path.baseUrl + 'btcpay/api-key-callback?token=' + encodeURIComponent(token) + '&gateway_id=' + encodeURIComponent(gatewayId);
          
          const params = new URLSearchParams();
          REQUIRED_PERMISSIONS.forEach(function(permission) {
            params.append('permissions', permission);
          });
          params.append('applicationName', 'Drupal Commerce');
          params.append('strict', 'true');
          params.append('selectiveStores', 'true');
          params.append('redirect', callbackUrl);

          const authUrl = serverUrl.replace(/\/$/, '') + '/api-keys/authorize?' + params.toString();

          console.log('Redirecting to BTCPay:', authUrl);

          // Redirect to BTCPay Server for authorization
          window.location.href = authUrl;
        });
      });

      function isValidUrl(serverUrl) {
        try {
          const url = new URL(serverUrl);
          if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return false;
          }
          if (url.hostname.endsWith('.local')) {
            if (!confirm(Drupal.t('You entered a .local domain which only works on your local network. Please make sure your BTCPay Server is reachable on the internet if you want to use it in production. Continue anyway?'))) {
              return false;
            }
          }
        } catch (e) {
          console.error(e);
          return false;
        }
        return true;
      }
    }
  };

})(Drupal, drupalSettings, once);
