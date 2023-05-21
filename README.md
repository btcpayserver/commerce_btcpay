# Commerce BTCPay

This module provides a [Drupal Commerce 2.x](https://www.drupal.org/project/commerce) payment plugin for [BTCPay Server](https://docs.btcpayserver.org). This allows you to accept cryptocurrencies without a 3rd party intermediary by becoming your own payment processor.

Visit the [project page on Drupal.org](https://drupal.org/project/commerce_btcpay)

## Demo store
A Drupal Commerce demo store connected with a (testnet) BTCPay Server where you can try the checkout (Bitcoin + Lightning Network) can be found here:   
[http://drupal.demo.btcpay.tech](http://drupal.demo.btcpay.tech/)

## Requirements

* BTCPay Server ([self hosted or 3rd party](https://docs.btcpayserver.org/deployment/deployment) or [quick start with a testserver](https://docs.btcpayserver.org/btcpay-basics/tryitout))
* Drupal Commerce 2.x installed ([installation guide](https://docs.drupalcommerce.org/commerce2/developer-guide/install-update/installation))  
* Drupal: [configured and writable private file system](https://www.drupal.org/docs/8/core/modules/file/overview#content-accessing-private-files)

## Installation and configuration

Short walkthrough screencast on installing and configuring the module:
https://youtu.be/XBZwyC2v48s

### Quick walkthrough steps

#### Generate pairing code on BTCPay server
1.  BTCPay server: [create and configure a store](https://docs.btcpayserver.org/btcpay-basics/gettingstarted#creating-btcpay-store)
2.  in store settings go to "**Access Tokens**"
3.  click on **[Create a new token]**
4.  **Label:** enter some label (eg. my store)
5.  **Public key:** this needs to be left **empty**
6.  **Facade:** "merchant"
7.  click on **[Request pairing]**
8.  on next screen choose your configured store in** Pair to** select dropdown and click on **[approve]**
9.  note down the displayed 7-digit code at the top status message, e.g. "d7afaXr"   
 (you will need that code below on gateway configuration, see below)

#### Commerce BTCPay: Installation + configuration
1.  install module: `composer require drupal/commerce_btcpay`
2.  enable the module: `drush en commerce_btcpay -y`
3.  make sure you have configured [private file system](https://www.drupal.org/docs/8/core/modules/file/overview#content-accessing-private-files) (needed to store encrypted public+private key)
4.  Commerce BTCPay configuration (**Commerce -> Configuration -> Payment -> Payment gateways**): 
5.  add payment method "BTCPay"
    * **Mode**: Test or Live (you can configure both individually)
    * **Test/Live server host**: enter your URL without https:// prefix e.g. btcpay.yourserver.com (note valid SSL certificate needed)
    * **Test/Live Paring code**: enter the 7-digit pairing code from BTCPay "Access tokens" page
    * **Save**  
      You should see a message that the tokens were successfully created.

## Status
**This module is currently in alpha stage but has been working for a while without issues.**    
Releases will be made available through the project page on drupal.org https://drupal.org/project/commerce_btcpay

## About BTCPay Server
Short excerpt from [their project page](https://github.com/btcpayserver/btcpayserver):
>BTCPay Server is a self-hosted, open-source cryptocurrency payment processor. It's secure, private, censorship-resistant and free.

**To get a full overview check out our [documentation](https://docs.btcpayserver.org).**


## Supported cryptocurrencies
BTCPay supports a vast variety of cryptocurrencies:
- BTC (Bitcoin)
- BTC 2nd layer [Lightning Network](https://bitcoiner.guide/lightning/) for instant settled low fee transactions
- some altcoins using full node integration (XMR, LTC, ...)
- and most other major altcoins through plugins via Trocador, SideShift and FixedFloat

## Compatible with BitPay API
BTCPay was created to be a alternative to 3rd party payment provider [BitPay](https://bitpay.com). Therefore BTCPay is invoice API compatible and you can use this payment plugin also with the official BitPay API and sites if you want. But the power of BTCPay is that you can become your own payment provider. 

Teaser, future versions of this plugin will be based on the BTCPay Server Greenfield API which is much more powerful and allows more features.


## Get Support
You can open an issue on our [Github repository](https://github.com/btcpayserver/commerce_btcpay/issues) or join us on [Telegram](https://t.me/btcpayserver) or [Mattermost chat](http://chat.btcpayserver.org/)