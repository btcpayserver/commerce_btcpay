# Commerce BTCPay

This module provides a [Drupal Commerce 2.x](https://www.drupal.org/project/commerce) payment plugin for [BTCPay Server](https://github.com/btcpayserver/btcpayserver). This allows you to accept cryptocurrencies without a 3rd party intermediary by becoming your own payment processor.

Visit the [project page on Drupal.org](https://drupal.org/project/commerce_btcpay)

## Status
**This module is currently in alpha stage and under active development!** 
Releases will be made available through the project page on drupal.org https://drupal.org/project/commerce_btcpay

## About BTCpay Server
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
- 2nd layer [Lightning Network](https://lightning.network) for micro-transactions (BTC + LTC)
- some altcoins using full node integration
- or most other major altcoins using Shapeshift.io for conversion

## Compatible with BitPay API
BTCPay was created to be a alternative to 3rd party payment provider [BitPay](https://bitpay.com). Therefore BTCPay is invoice API compatible and you can use this payment plugin also with the official BitPay API and sites if you want. But the power of BTCPay is that you can become your own payment provider.
