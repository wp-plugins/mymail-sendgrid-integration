=== MyMail SendGrid Integration ===
Contributors: revaxarts
Tags: sendgrid, mymail, delivery, deliverymethod, newsletter, email, revaxarts, mymailesp
Requires at least: 3.7
Tested up to: 4.0
Stable tag: 0.3.1
License: GPLv2 or later

== Description ==

> This Plugin requires [MyMail Newsletter Plugin for WordPress](http://rxa.li/mymail?utm_source=SendGrid+integration+for+MyMail)

Uses SendGrid to deliver emails for the [MyMail Newsletter Plugin for WordPress](http://rxa.li/mymail).

== Installation ==

1. Upload the entire `mymail-sendgrid-integration` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings => Newsletter => Delivery and select the `SendGrid` tab
4. Enter your credentials
5. Send a testmail

== Changelog ==

= 0.3.1 =

* secure settings now applies to WEB API as well

= 0.3 =

* moved to class based structure
* fixed missing tracking pixel when WEB API is used

= 0.2.5 =

* fixed verification problems

= 0.2.4 =
* sending via SMTP is now faster

= 0.2.3 =
* fixed a bug where mails are not send at an early stage of the page load

= 0.2.2 =
* added port check for SMTP connection

= 0.2.1 =
* small bug fixes

= 0.2 =
* small bug fixes

= 0.1 =
* initial release

== Upgrade Notice ==

== Additional Info One ==

This Plugin requires [MyMail Newsletter Plugin for WordPress](http://rxa.li/mymail?utm_source=SendGrid+integration+for+MyMail)

