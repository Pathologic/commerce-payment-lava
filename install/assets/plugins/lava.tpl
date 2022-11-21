//<?php
/**
 * Payment Lava
 *
 * Lava payments processing
 *
 * @category    plugin
 * @version     1.0.0
 * @author      Pathologic
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @properties &title=Title;text; &shop_id=Shop ID;text; &secret_key=Secret Key;text; &add_key=Additional Key;text; &debug=Debug;list;No==0||Yes==1;1 
 * @internal    @modx_category Commerce
 * @internal    @installset base
 */

return require MODX_BASE_PATH . 'assets/plugins/lava/plugin.lava.php';
