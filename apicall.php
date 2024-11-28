<?php
/**
 * Plugin Name:apicall
 * Description: جلب بيانات الطلبات والمنتجات وبيانات العملاء
 * Version: 1.0.0
 * Author: Your Name
 */

// منع الوصول المباشر للملف
if (!defined('ABSPATH')) {
    exit;
}

class OrdersDataRetrievalPlugin {
    
    /**
     * تهيئة الإضافة وتسجيل الإجراءات
     */
    public function __construct() {
        // تسجيل نقطة النهاية REST API
        add_action('rest_api_init', array($this, 'register_orders_endpoint'));
    }

    /**
     * تسجيل نقطة النهاية الخاصة بجلب بيانات الطلبات
     */
    public function register_orders_endpoint() {
        register_rest_route('custom/v1', '/orders', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_orders_data'),
            'permission_callback' => array($this, 'check_api_permissions')
        ));

        register_rest_route('custom/v1', '/orders/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_single_order'),
            'permission_callback' => array($this, 'check_api_permissions'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                )
            )
        ));
    }

    /**
     * التحقق من صلاحيات الوصول للـ API
     * @return bool
     */
    public function check_api_permissions() {
        // يمكن تخصيص التحقق من الصلاحيات هنا
        return current_user_can('manage_options');
    }

    /**
     * جلب بيانات الطلبات
     * @param WP_REST_Request $request 
     * @return WP_REST_Response
     */
    public function get_orders_data($request) {
        // استخراج معايير البحث من الطلب
        $page = $request->get_param('page') ? absint($request->get_param('page')) : 1;
        $per_page = $request->get_param('per_page') ? absint($request->get_param('per_page')) : 10;
        $status = $request->get_param('status') ? sanitize_text_field($request->get_param('status')) : 'any';

        // استعلام الطلبات
        $orders_query = wc_get_orders(array(
            'status' => $status,
            'paginate' => true,
            'page' => $page,
            'limit' => $per_page
        ));

        // تجهيز مصفوفة النتائج
        $orders_data = array();

        foreach ($orders_query->orders as $order) {
            $order_data = $this->prepare_order_response($order);
            $orders_data[] = $order_data;
        }

        // إعداد روابط التصفح
        $base_url = rest_url('custom/v1/orders');
        $max_pages = $orders_query->max_pages;
        
        $links = array();
        
        // رابط الصفحة الأولى
        $links['first'] = add_query_arg(array(
            'page' => 1,
            'per_page' => $per_page,
            'status' => $status
        ), $base_url);
        
        // رابط الصفحة السابقة
        if ($page > 1) {
            $links['prev'] = add_query_arg(array(
                'page' => $page - 1,
                'per_page' => $per_page,
                'status' => $status
            ), $base_url);
        }
        
        // رابط الصفحة التالية
        if ($page < $max_pages) {
            $links['next'] = add_query_arg(array(
                'page' => $page + 1,
                'per_page' => $per_page,
                'status' => $status
            ), $base_url);
        }
        
        // رابط الصفحة الأخيرة
        $links['last'] = add_query_arg(array(
            'page' => $max_pages,
            'per_page' => $per_page,
            'status' => $status
        ), $base_url);

        // إعداد استجابة REST API
        $response = array(
            'orders' => $orders_data,
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $orders_query->total,
                'total_pages' => $max_pages,
                'links' => $links
            )
        );

        // إضافة headers للتصفح
        $response_obj = rest_ensure_response($response);
        $response_obj->header('X-WP-Total', $orders_query->total);
        $response_obj->header('X-WP-TotalPages', $max_pages);

        return $response_obj;
    }

    /**
     * تحضير بيانات الطلب مع تفاصيل إضافية
     * @param WC_Order $order الطلب
     * @return array بيانات الطلب المُعدة
     */
    private function prepare_order_response($order) {
        $order_id = $order->get_id();
        $complete_data = $this->get_complete_order_data($order_id);
        
        // جلب بيانات العميل
        $customer = $order->get_user();
        $customer_meta = [];
        
        if ($customer) {
            // جلب جميع البيانات الوصفية للعميل
            $customer_meta = array_map(function($meta) {
                return reset($meta);
            }, get_user_meta($customer->ID));
        }

        // بيانات الطلب الأساسية
        $order_data = array(
            'id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'customer_info' => array(
                'id' => $customer ? $customer->ID : null,
                'email' => $order->get_billing_email(),
                'name' => $order->get_formatted_billing_full_name(),
                'phone' => $order->get_billing_phone(),
                'meta_data' => $customer_meta,
                'billing_address' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'company' => $order->get_billing_company(),
                    'address_1' => $order->get_billing_address_1(),
                    'address_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone()
                )
            ),
            'products' => $this->get_order_products($order),
            'meta_data' => $this->get_order_meta($order)
        );

        // إضافة البيانات الجديدة
        $order_data['detailed_data'] = $complete_data;
        
        return $order_data;
    }

    /**
     * جلب تفاصيل المنتجات في الطلب مع البيانات الوصفية
     * @param WC_Order $order الطلب
     * @return array قائمة المنتجات
     */
    private function get_order_products($order) {
        $products = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            
            // جلب البيانات الإضافية للمنتج
            $meta_lookup = $this->get_product_meta_lookup($product_id);
            $attributes_lookup = $this->get_product_attributes_lookup($product_id);
            
            // جلب جميع البيانات الوصفية للمنتج
            $product_meta = get_post_meta($product->get_id());
            $formatted_meta = array();
            
            foreach ($product_meta as $meta_key => $meta_values) {
                $formatted_meta[$meta_key] = reset($meta_values);
            }

            $products[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total() / $item->get_quantity(),
                'total' => $item->get_total(),
                'sku' => $product->get_sku(),
                'categories' => wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names')),
                'attributes' => $product->get_attributes(),
                'meta_data' => $formatted_meta,
                'image' => wp_get_attachment_url($product->get_image_id()),
                'gallery_images' => $this->get_product_gallery($product),
                'meta_lookup' => $meta_lookup,
                'attributes_lookup' => $attributes_lookup
            );
        }

        return $products;
    }

    /**
     * جلب صور معرض المنتج
     * @param WC_Product $product المنتج
     * @return array روابط الصور
     */
    private function get_product_gallery($product) {
        $gallery_image_ids = $product->get_gallery_image_ids();
        $gallery_images = array();
        
        foreach ($gallery_image_ids as $image_id) {
            $gallery_images[] = wp_get_attachment_url($image_id);
        }
        
        return $gallery_images;
    }

    /**
     * جلب البيانات الوصفية للطلب
     * @param WC_Order $order الطلب
     * @return array البيانات الوصفية
     */
    private function get_order_meta($order) {
        $order_meta = get_post_meta($order->get_id());
        $formatted_meta = array();
        
        foreach ($order_meta as $meta_key => $meta_values) {
            // تجاهل البيانات الوصفية الداخلية
            if (strpos($meta_key, '_') === 0) {
                continue;
            }
            $formatted_meta[$meta_key] = reset($meta_values);
        }
        
        return $formatted_meta;
    }

    /**
     * جلب تفاصيل طلب واحد
     * @param WP_REST_Request $request 
     * @return WP_REST_Response
     */
    public function get_single_order($request) {
        $order_id = $request['id'];
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error(
                'not_found',
                'الطلب غير موجود',
                array('status' => 404)
            );
        }

        $order_data = $this->prepare_order_response($order);
        return rest_ensure_response($order_data);
    }

    /**
     * جلب البيانات الإضافية للمنتج من جدول meta_lookup
     * @param int $product_id معرف المنتج
     * @return array بيانات المنتج الإضافية
     */
    private function get_product_meta_lookup($product_id) {
        global $wpdb;
        
        $meta_lookup = $wpdb->get_row($wpdb->prepare("
            SELECT 
                sku,
                virtual,
                downloadable,
                min_price,
                max_price,
                onsale,
                stock_quantity,
                stock_status,
                rating_count,
                average_rating,
                total_sales,
                tax_status,
                tax_class
            FROM {$wpdb->prefix}wc_product_meta_lookup 
            WHERE product_id = %d
        ", $product_id), ARRAY_A);

        return $meta_lookup ?: array();
    }

    /**
     * جلب سمات المنتج من جدول attributes_lookup
     * @param int $product_id معرف المنتج
     * @return array سمات المنتج
     */
    private function get_product_attributes_lookup($product_id) {
        global $wpdb;
        
        $attributes = $wpdb->get_results($wpdb->prepare("
            SELECT 
                product_or_parent_id,
                product_id,
                taxonomy,
                term_id,
                is_variation_attribute,
                in_stock
            FROM {$wpdb->prefix}wc_product_attributes_lookup 
            WHERE product_id = %d
        ", $product_id), ARRAY_A);

        // جلب أسماء السمات
        foreach ($attributes as &$attribute) {
            $term = get_term($attribute['term_id'], $attribute['taxonomy']);
            if (!is_wp_error($term)) {
                $attribute['term_name'] = $term->name;
            }
        }

        return $attributes;
    }

    /**
     * جلب تفاصيل فئات السمات
     * @return array فئات السمات
     */
    private function get_attribute_taxonomies() {
        global $wpdb;
        
        $taxonomies = $wpdb->get_results("
            SELECT 
                attribute_id,
                attribute_name,
                attribute_label,
                attribute_type,
                attribute_orderby,
                attribute_public
            FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
        ", ARRAY_A);

        return $taxonomies;
    }

    /**
     * جلب تفاصيل الطلب من جميع الجداول المرتبطة
     * @param int $order_id معرف الطلب
     * @return array بيانات الطلب الكاملة
     */
    private function get_complete_order_data($order_id) {
        global $wpdb;
        
        // جلب بيانات الطلب الأساسية
        $order_data = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}wc_orders
            WHERE id = %d
        ", $order_id), ARRAY_A);

        if (!$order_data) {
            return array();
        }

        // جلب البيانات الإضافية للطلب
        $order_meta = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key, meta_value
            FROM {$wpdb->prefix}wc_orders_meta
            WHERE order_id = %d
        ", $order_id), ARRAY_A);

        // جلب عناوين الطلب
        $order_addresses = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}wc_order_addresses
            WHERE order_id = %d
        ", $order_id), ARRAY_A);

        // جلب عناصر الطلب
        $order_items = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}woocommerce_order_items
            WHERE order_id = %d
        ", $order_id), ARRAY_A);

        // جلب البيانات الإضافية لكل عنصر
        foreach ($order_items as &$item) {
            $item['meta_data'] = $wpdb->get_results($wpdb->prepare("
                SELECT meta_key, meta_value
                FROM {$wpdb->prefix}woocommerce_order_itemmeta
                WHERE order_item_id = %d
            ", $item['order_item_id']), ARRAY_A);
        }

        // جلب إحصائيات الطلب
        $order_stats = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}wc_order_stats
            WHERE order_id = %d
        ", $order_id), ARRAY_A);

        // جلب بيانات المنتجات في الطلب
        $order_products = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}wc_order_product_lookup
            WHERE order_id = %d
        ", $order_id), ARRAY_A);

        // تجميع كل البيانات
        return array(
            'order' => $order_data,
            'meta' => $order_meta,
            'addresses' => $order_addresses,
            'items' => $order_items,
            'stats' => $order_stats,
            'products' => $order_products
        );
    }
}

// تهيئة البلاجن
function initialize_orders_retrieval_plugin() {
    new OrdersDataRetrievalPlugin();
}
add_action('plugins_loaded', 'initialize_orders_retrieval_plugin');



/**
 * Firebase Notifications Integration
 * 
 * @package ApicallNotifications
 * @version 1.0.0
 */

// منع الوصول المباشر للملف
if (!defined('ABSPATH')) {
    exit;
}



/**
 * Plugin Name: ApicallNotifications
 * Description: إدارة الإشعارات باستخدام Firebase في وردبريس
 * Version: 1.0.2
 * Author: Your Name
 * 
 * @package ApicallNotifications
 */

// منع الوصول المباشر للملف
if (!defined('ABSPATH')) {
    exit;
}

// تحميل المكتبات المطلوبة
require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Exception\DatabaseException;

/**
 * Class ApicallNotificationsManager
 * مسؤولة عن إدارة الإشعارات والتكامل مع Firebase
 * 
 * @package ApicallNotifications
 * @version 1.0.2
 */
class ApicallNotificationsManager {
    /**
     * مسار ملف اعتماد Firebase
     * 
     * @var string
     */
    private $serviceAccountPath;

    /**
     * مسار قاعدة بيانات Firebase
     * 
     * @var string
     */
    private $databaseUrl;

    /**
     * البناء
     * 
     * @param string $credentialsPath مسار ملف الاعتماد
     * @param string $databaseUrl عنوان قاعدة البيانات
     */
    public function __construct(
        $credentialsPath = '', 
        $databaseUrl = 'https://esimly-6a032-default-rtdb.firebaseio.com/'
    ) {
        // تحديد المسار الافتراضي لملف الاعتمادات إذا لم يتم تمريره
        $this->serviceAccountPath = $credentialsPath ?: __DIR__ . '/esimly-6a032-firebase-adminsdk-8mbj9-e002b16bf8.json';
        $this->databaseUrl = $databaseUrl;
    }

    /**
     * تهيئة اتصال Firebase
     * 
     * @return \Kreait\Firebase\Database
     * @throws \Exception في حالة فشل الاتصال
     */
    private function initFirebase() {
        // التحقق من وجود ملف الاعتماد
        if (!file_exists($this->serviceAccountPath)) {
            throw new \Exception('ملف اعتماد Firebase غير موجود');
        }

        try {
            $factory = (new Factory)->withServiceAccount($this->serviceAccountPath);
            $database = $factory->withDatabaseUri($this->databaseUrl)->createDatabase();

            return $database;
        } catch (\Exception $e) {
            error_log('خطأ في تهيئة Firebase: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * جلب الإشعارات من Firebase
     * 
     * @param array $filters المرشحات الاختيارية للإشعارات
     * @return array بيانات الإشعارات
     * @throws DatabaseException خطأ في جلب البيانات
     */
    public function getNotifications($filters = []) {
        try {
            $database = $this->initFirebase();
            $reference = $database->getReference('notifications');
            
            $data = $reference->getValue();
            
            // تطبيق المرشحات
            if (!empty($filters)) {
                $data = $this->applyFilters($data, $filters);
            }
            
            return $data ?? [];
        } catch (\Exception $e) {
            error_log('خطأ في جلب الإشعارات: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * تطبيق المرشحات على الإشعارات
     * 
     * @param array $notifications الإشعارات
     * @param array $filters المرشحات
     * @return array الإشعارات المفلترة
     */
    private function applyFilters($notifications, $filters) {
        return array_filter($notifications, function($notification) use ($filters) {
            foreach ($filters as $key => $value) {
                if (!isset($notification[$key]) || $notification[$key] != $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * تسجيل نقاط النهاية REST API
     */
    public function registerRestApi() {
        add_action('rest_api_init', function () {
            // روت جلب الإشعارات الأساسي
            register_rest_route('apicall/v1', '/notifications', [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'notificationsEndpoint'],
                'permission_callback' => [$this, 'checkApiPermissions'],
                'args' => [
                    'type' => [
                        'validate_callback' => function($value) {
                            return is_string($value);
                        }
                    ],
                    'status' => [
                        'validate_callback' => function($value) {
                            return is_string($value);
                        }
                    ]
                ]
            ]);

            // روت جلب إشعار محدد
            register_rest_route('apicall/v1', '/notifications/(?P<id>[\d]+)', [
                'methods' => 'GET',
                'callback' => [$this, 'singleNotificationEndpoint'],
                'permission_callback' => [$this, 'checkApiPermissions'],
                'args' => [
                    'id' => [
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ]
                ]
            ]);
        });
    }

    /**
     * التحقق من صلاحيات الوصول للـ API
     * 
     * @return bool هل يُسمح بالوصول
     */
    public function checkApiPermissions() {
        // يمكن تخصيص التحقق من الصلاحيات هنا
        // مثال: التحقق من تسجيل الدخول
        return is_user_logged_in();
    }

    /**
     * نقطة النهاية لجلب الإشعارات
     * 
     * @param WP_REST_Request $request طلب REST
     * @return WP_REST_Response استجابة REST
     */
    public function notificationsEndpoint($request) {
        try {
            // استخراج المرشحات
            $filters = [];
            if ($request->get_param('type')) {
                $filters['type'] = $request->get_param('type');
            }
            if ($request->get_param('status')) {
                $filters['status'] = $request->get_param('status');
            }

            // استخراج معايير الصفحة
            $page = $request->get_param('page') ?: 1;
            $per_page = $request->get_param('per_page') ?: 10;

            $notifications = $this->getNotifications($filters);
            
            // التقسيم للصفحات
            $total = count($notifications);
            $max_pages = ceil($total / $per_page);
            $offset = ($page - 1) * $per_page;
            $notifications = array_slice($notifications, $offset, $per_page);

            $response = [
                'notifications' => $notifications,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $per_page,
                    'total_items' => $total,
                    'total_pages' => $max_pages
                ]
            ];
            
            return new WP_REST_Response($response, 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'error' => 'حدث خطأ أثناء جلب الإشعارات',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * نقطة النهاية لجلب إشعار محدد
     * 
     * @param WP_REST_Request $request طلب REST
     * @return WP_REST_Response استجابة REST
     */
    public function singleNotificationEndpoint($request) {
        try {
            $id = $request['id'];
            $notifications = $this->getNotifications();
            
            $notification = null;
            foreach ($notifications as $item) {
                if (isset($item['id']) && $item['id'] == $id) {
                    $notification = $item;
                    break;
                }
            }

            if (!$notification) {
                return new WP_REST_Response(['message' => 'الإشعار غير موجود'], 404);
            }
            
            return new WP_REST_Response($notification, 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'error' => 'حدث خطأ أثناء جلب الإشعار',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}

/**
 * تهيئة وتشغيل الإضافة
 */
function initialize_notifications_plugin() {
    $notificationsManager = new ApicallNotificationsManager();
    $notificationsManager->registerRestApi();
}
add_action('plugins_loaded', 'initialize_notifications_plugin');



