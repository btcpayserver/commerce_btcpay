# Commerce BTCPay

This module provides a [Drupal Commerce 2.x](https://www.drupal.org/project/commerce) payment plugin for [BTCPay Server](https://github.com/btcpayserver/btcpayserver). This allows you to accept cryptocurrencies without a 3rd party intermediary by becoming your own payment processor.

Visit the [project page on Drupal.org](https://drupal.org/project/commerce_btcpay)

## Requirements

* BTCPay Server (see [docker setup guide](https://github.com/btcpayserver/btcpayserver-docker))
* Drupal Commerce 2.x installed ([installation guide](https://docs.drupalcommerce.org/commerce2/developer-guide/install-update/installation))  
* Drupal: [configured private file system](https://www.drupal.org/docs/8/core/modules/file/overview#content-accessing-private-files)

## Installation and configuration

Short walkthrough screencast on installing and configuring the module:
https://youtu.be/XBZwyC2v48s

### Quick walkthrough steps

#### Generate pairing code on BTCPay server
1.  in store settings go to "**Access Tokens**"
2.  click on **[Create a new token]**
3.  **Label:** enter some label (eg. my store)
4.  **Public key:** this needs to be left **empty**
5.  **Facade:** "merchant"
6.  click on **[Request pairing]**
7.  on next screen choose your configured store in** Pair to** select dropdown and click on **[approve]**
8.  note down the displayed 7-digit code at the top status message, e.g. "d7afaXr"   
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
**This module is currently in alpha stage and under active development.**    
Releases will be made available through the project page on drupal.org https://drupal.org/project/commerce_btcpay

## About BTCPay Server
Short excerpt from [their project page](https://github.com/btcpayserver/btcpayserver):
>BTCPay Server is an Open Source payment processor, written in C#, that conforms to the invoice API of Bitpay. This allows easy migration of your code base to your own, self-hosted payment processor.
> 
>This solution is for you if:
> 
> - You are currently using Bitpay as a payment processor but are worried about their commitment to Bitcoin in the future
> - You want to be in control of your own funds
 Bitpay compliance team decided to reject your application
> - You want lower fees (we support Segwit)

## Supported cryptocurrencies
BTCPay supports a vast variety of cryptocurrencies:
- BTC (Bitcoin)
- LTC (Litecoin)
- 2nd layer [Lightning Network](https://lightning.network) for instant micro-transactions (BTC + LTC)
- some altcoins using full node integration
- or most other major altcoins using Shapeshift.io for conversion

## Compatible with BitPay API
BTCPay was created to be a alternative to 3rd party payment provider [BitPay](https://bitpay.com). Therefore BTCPay is invoice API compatible and you can use this payment plugin also with the official BitPay API and sites if you want. But the power of BTCPay is that you can become your own payment provider.
