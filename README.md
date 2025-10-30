# Accept Bitcoin on your Drupal Commerce Store using BTCPay Server

Introducing BTCPay Server payment module for [Drupal Commerce 2.x or 3.x](https://www.drupal.org/project/commerce). Drupal Commerce Store owners can now accept payments using Bitcoin and other cryptocurrencies directly through BTCPay Server without any third-party intermediary.

BTCPay Server supports a wide range of cryptocurrencies, with the potential for future extensions. Here are the currencies you can use now on BTCPay Server:

- BTC (Bitcoin)
- Bitcoin layer-two network (the Lightning Network) for fast and zero/low-fee transactions
- Altcoins with full node integration including coins like Monero (XMR) and Litecoin (LTC).
- Other Major Altcoins: Supported through plugins via platforms such as [Trocador](https://docs.btcpayserver.org/Trocador/), [SideShift](https://docs.btcpayserver.org/SideShift/), and FixedFloat.

Want to accept Bitcoin on your Drupal Commerce store? Visit the [project page on Drupal.org](https://drupal.org/project/commerce_btcpay)

## Demo store
A Drupal Commerce demo store connected with a (testnet) BTCPay Server where you can try the checkout (Bitcoin + Lightning Network) can be found here:   
[http://drupal.demo.btcpay.tech](http://drupal.demo.btcpay.tech/)

## Requirements

* BTCPay Server ([self hosted or 3rd party](https://docs.btcpayserver.org/deployment/deployment) or [quick start with a testserver](https://docs.btcpayserver.org/btcpay-basics/tryitout))
* Drupal Commerce 2.x or 3.x installed ([installation guide](https://docs.drupalcommerce.org/commerce2/developer-guide/install-update/installation))  

## Upgrading

As of version 3.x of this module it uses the BTCPay Server Greenfield API which is much more powerful and allows more features. Version 3.x is a breaking change and also new libraries and API tokens are used. So please uninstall old versions (1.x and 2.x) before you install the latest 3.x release. And follow the setup instructions below.


## Installation and configuration Guide for the BTCPay Server - Drupal Commerce Integration

Ready to accept Bitcoin on your Drupal Commerce Store? Follow this quick and easy guide to install and configure the BTCPay Drupal Commerce module. For a quick run through, check out our installation and configuration screencast:

[![BTCPay Server - Drupal Commerce 2.x quick walkthrough](https://img.youtube.com/vi/XBZwyC2v48s/mqdefault.jpg)](https://youtube.com/watch?v=XBZwyC2v48s)


### Easy setup steps

#### Generate pairing code on BTCPay server
1.  **Setup your store:** You'd need a BTCPay server instance to get started. Don't have one? click [here](https://docs.btcpayserver.org/RegisterAccount/) for a step-by-step guide.
2.  Make sure you have setup at least Bitcoin or Lightning wallet.

#### Commerce BTCPay: Installation + configuration
1. Install module: `composer require drupal/commerce_btcpay`
2. Enable the module: `drush en commerce_btcpay -y`
3. Go to Commerce BTCPay configuration (**Commerce -> Configuration -> Payment -> Payment gateways**): 
5. Click on **[Add payment gateway]**
6. Enter the BTCPay Server URL (e.g. https://btcpay.yourdomain.tld). (This is where you created your store, see requirements for how to setup a BTCPay Server.)
7. You can now click **Generate API Key** and you will get redirected to BTCPay Server authorization page.
8. Select the store you want to connect to and click **[Continue]**
9. On the next screen enter a label e.g. "Drupal 11 store".
10. At the bottom click **[Authorize app]**
11. You will get redirected to your Drupal Commerce store and you should see that the store id, API key and webhook was saved.
12. Done, you can now test the payment gateway.

## Status
**This module is currently in alpha stage but has proven stable without issues.**    
Future updates and releases will be available on the [project page on drupal.org](https://drupal.org/project/commerce_btcpay)

## About BTCPay Server
>[BTCPay Server](https://btcpayserver.org/) is a self-hosted, open-source cryptocurrency payment processor know for its security, privacy, and censorship resistance.

It's free to use and allows you to become your own payment processor. 
**To get a full overview, check out our [documentation](https://docs.btcpayserver.org).**

## Get Support
You can open an issue on our [Github repository](https://github.com/btcpayserver/commerce_btcpay/issues) or reach us on [Telegram](https://t.me/btcpayserver) or [Mattermost chat](http://chat.btcpayserver.org/)
