<?php

/**
 * Pack / package / carton selling (client/tobacco, docs/packaging.md). Arabic twin of lang/en/packaging.php.
 * The three unit words (باكيت / كروز / كرتونة) are the usual tobacco-shop terms: confirm them with the client.
 */
return [
    'unit' => [
        'pack' => 'باكيت',
        'package' => 'كروز',
        'carton' => 'كرتونة',
    ],
    'unit_x' => ':unit × :n',
    'receive_note' => 'استلام :qty × :unit = :packs باكيت',

    'errors' => [
        'unit_not_supported' => 'لا يمكن بيع :product بوحدة :unit.',
        'receive_unit_not_available' => 'المنتج :product غير مُعدّ بوحدة :unit.',
        'simple_only' => 'التغليف متاح للمنتجات البسيطة التي ليس لها متغيّرات فقط.',
        'barcode_taken' => 'هذا الباركود مستخدم مسبقاً للمنتج :name.',
        'carton_equals_pack' => 'يجب أن يختلف باركود الكرتونة عن باركود الباكيت.',
        'carton_needs_package' => 'الكرتونة تتطلب تفعيل خيار «يُباع أيضاً ككروز» أولاً.',
    ],

    'report' => [
        'packs' => 'باكيت',
        'packages_n' => ':n كروز',
        'cartons_n' => ':n كرتونة',
    ],

    'form' => [
        'section' => 'التغليف',
        'section_hint' => 'بيع هذا المنتج كباكيت، وككروز من عدة باكيتات، وككرتونة. يُحسب المخزون دائماً بالباكيت.',
        'simple_only_hint' => 'التغليف متاح للمنتجات البسيطة فقط: ليس للمنتجات ذات المتغيّرات أو الحزم أو الخدمات.',
        'has_package' => 'يُباع أيضاً ككروز من عدة باكيتات',
        'package_size' => 'عدد الباكيتات في الكروز',
        'package_prices' => 'أسعار الكروز',
        'price_retail' => 'مفرق',
        'price_wholesale' => 'جملة',
        'price_vip' => 'VIP',
        'per_pack' => '= :price للباكيت',
        'has_carton' => 'له باركود كرتونة',
        'carton_barcode' => 'باركود الكرتونة',
        'carton_barcode_placeholder' => 'امسح الكرتونة',
        'packages_per_carton' => 'عدد الكروزات في الكرتونة',
        'packs_per_carton' => 'عدد الباكيتات في الكرتونة',
        'sell_carton' => 'بيع الكرتونة في نقطة البيع',
        'sell_carton_hint' => 'عند الإيقاف: يُستخدم باركود الكرتونة لاستلام المخزون فقط.',
        'carton_prices' => 'أسعار الكرتونة',
        'carton_is_pack' => 'باركود الكرتونة مطابق لباركود الباكيت.',
    ],

    'receive' => [
        'unit' => 'الوحدة',
        'scan_carton' => 'مسح كرتونة',
        'scan_carton_placeholder' => 'امسح باركود الكرتونة لاستلامها',
        'carton_unknown' => 'لا يوجد منتج بهذا الباركود.',
        'carton_added' => ':name: استلام :n كرتونة، أي :packs باكيت. راجع الكمية ثم اضغط «تعديل المخزون».',
        'packs_added' => '= :packs باكيت',
        'default_reason' => 'استلام مخزون',
        'cost_per_pack' => '= :cost للباكيت',
    ],

    'ui' => [
        'choose_title' => 'كيف يُباع؟',
        'choose_hint' => 'المفاتيح: 1 باكيت · 2 كروز · 3 كرتونة · Enter باكيت · Esc إلغاء',
        'carton_not_sold' => 'هذا باركود كرتونة: الكرتونة لا تُباع هنا. استخدم استلام المخزون.',
        'line_dropped' => 'تم استبعاد :name: وحدة :unit لم تعد متاحة للبيع.',
        'switch_unit' => 'التبديل بين الباكيت والكروز',
        'per_unit' => ':price للوحدة',
    ],
];
