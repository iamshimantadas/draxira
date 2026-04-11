=== Draxira – Dummy Content Generator ===
Contributors: microcodes
Tags: dummy content, dummy posts, test data, dummy users, content generator
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate dummy posts, pages, custom post types, users, and WooCommerce products instantly.

== Description ==

Draxira is a powerful WordPress plugin that helps you generate realistic dummy content for testing, development, and demonstration purposes. It supports various content types with advanced configuration options and integrates seamlessly with popular plugins like WooCommerce, ACF, and CMB2.

= Key Features =

* **📝 Posts & Pages** - Generate dummy posts for any post type with customizable content
* **👥 Users** - Create dummy users with custom meta fields and roles
* **🛍️ WooCommerce Products** - Full product generation with categories, tags, attributes, and variations
* **🔧 Custom Field Support** - Automatically detects and fills ACF and CMB2 fields
* **🏷️ Taxonomy Management** - Automatically create and assign dummy taxonomy terms
* **🖼️ Media Support** - Add featured images and product galleries automatically
* **🗑️ Bulk Deletion** - Easily delete all generated dummy content with one click
* **⚡ Performance Optimized** - Handles large volumes of content efficiently

= What Can You Generate? =

| Content Type | Features |
|--------------|----------|
| **Posts & Pages** | Titles, content, excerpts, featured images, authors, custom fields |
| **Custom Post Types** | Full support for any registered custom post type |
| **Users** | Usernames, emails, passwords, roles, first/last names, bio, custom meta |
| **WooCommerce Products** | Simple & variable products, prices, SKUs, stock, categories, tags, attributes, variations, images |
| **Taxonomies** | Automatic creation of category and tag terms |
| **Custom Fields** | ACF, CMB2, and custom post meta with intelligent data generation |

= How It Works =

1. Navigate to the **Draxira** menu in your WordPress admin panel
2. Choose from **Post Types**, **Users**, or **Products** tabs
3. Configure your generation options:
   - Select content type and quantity
   - Choose authors and statuses
   - Configure taxonomy creation and assignment
   - Map custom fields to realistic data types
4. Click **Generate** to create your dummy content
5. Use the **Manage** tabs to view or delete generated content

= Why Choose Draxira? =

* **Intelligent Data Generation** - Uses Faker PHP library for realistic, human-like content
* **Smart Field Detection** - Automatically identifies ACF and CMB2 fields for seamless integration
* **Safe & Reversible** - All dummy content is tagged and can be completely removed
* **Performance Optimized** - Bulk operations are optimized for speed and memory
* **Developer Friendly** - Clean code with hooks and filters for customization
* **Regular Updates** - Continuously improved with new features and compatibility

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to **Plugins → Add New**
3. Search for "Draxira Dummy Content Generator"
4. Click **Install Now** and then **Activate**

= Manual Installation =

1. Upload the `draxira` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the **Plugins** menu in WordPress
3. Access the plugin via the **Draxira** menu in your admin panel

= Requirements =

- WordPress 5.0 or higher
- PHP 7.2 or higher
- For WooCommerce features: WooCommerce 4.0 or higher

== Frequently Asked Questions ==

= Does this work with any WordPress theme? =

Yes, Draxira works with any WordPress theme and creates content using standard WordPress functions that all themes understand.

= Will this work with my page builder? =

Absolutely! The plugin creates standard WordPress content that works with all major page builders including Elementor, Beaver Builder, Divi, WPBakery, and Gutenberg.

= Can I generate WooCommerce products? =

Yes! Draxira fully supports WooCommerce product generation including:
- Simple and variable products
- Product categories and tags
- Product attributes and variations
- Prices, SKUs, and stock management
- Featured images and galleries

= Does it support ACF (Advanced Custom Fields)? =

Yes, Draxira automatically detects ACF fields associated with your post types and allows you to configure how each field should be filled with realistic data.

= Does it support CMB2? =

Yes, CMB2 fields are automatically detected and can be configured just like ACF fields.

= Is it safe to use on production sites? =

While Draxira is safe to use, we recommend using it on staging or development sites. However, if you use it on production:
- All dummy content is clearly marked
- You can easily delete all generated content
- No permanent changes are made to your core WordPress installation

= How do I delete generated content? =

1. Go to the relevant tab (Post Types, Users, or Products)
2. Click the **Manage** tab
3. Select the content type you want to delete
4. Use the **Delete All** button or individual delete links

= Will this slow down my site? =

Draxira is designed to be lightweight and efficient. It only loads its scripts on its own admin pages and uses optimized database queries for bulk operations.

= Can I contribute or report issues? =

Yes! Please visit our [GitHub repository](https://github.com/iamshimantadas/draxira) for issues, feature requests, and contributions.

== Screenshots ==

1. **Post Types Generation Interface** - Configure posts, pages, and custom post types
2. **Post Meta & Taxonomy** - Configure post-meta and taxonomy which assigned to post-type, their assigned per post.
3. **User Generation Interface** - Generate users along with user-meta with custom options.
4. **WooCommerce Products Generation Interface** - Generate woocommerce products with author, product meta, taxonomy and custom fields.

== Changelog ==

= 1.0.0 =
* Initial release
* **Post Types**
  - Generate posts for any public post type
  - Support for ACF and CMB2 custom fields
  - Automatic taxonomy term creation and assignment
  - Featured image support from plugin assets
  - Author selection and excerpt generation
* **Users**
  - Generate dummy users with customizable roles
  - Support for user meta fields including WooCommerce billing/shipping
  - Automatic first name, last name, and display name generation
  - Bulk delete functionality
* **WooCommerce Products**
  - Generate simple and variable products
  - Product categories, tags, and attributes
  - Product variations with custom pricing and stock
  - Featured images and product galleries
  - CSV-based product data for realistic names and descriptions
* **General Features**
  - Clean uninstall script removes all data
  - Multisite compatible
  - Internationalization ready
  - Admin interface with tabs for easy navigation
  - AJAX-powered configuration loading
  - Performance optimizations for bulk operations

== Upgrade Notice ==

= 1.0.0 =
Initial release of Draxira – Dummy Content Generator. Start generating realistic dummy content for your WordPress site today!

== Additional Information ==

= Plugin Support =

For support, feature requests, or bug reports, please visit our [support forum](https://wordpress.org/support/plugin/draxira) or [GitHub repository](https://github.com/iamshimantadas/draxira).

= Credits =

Draxira uses the following open-source libraries:
- [FakerPHP/Faker](https://github.com/fzaninotto/Faker) - For realistic data generation
- [WooCommerce](https://woocommerce.com/) - For product generation support

= Disclaimer =

This plugin is intended for testing and development purposes. While it's safe to use, always backup your database before generating large amounts of content.

= Privacy Notice =

Draxira does not collect any personal data. All generated content is stored locally in your WordPress database and can be completely removed using the plugin's delete functionality.