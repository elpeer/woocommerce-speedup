# WooHoo - WooCommerce Performance Optimizer

**WooCommerce, but faster. WooHoo!**

*Your shop on espresso: faster browsing, smoother checkout.*

![Version](https://img.shields.io/badge/version-1.1.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-green)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)

<p align="center">
  <img src="assets/images/woohoo-logo.png" alt="WooHoo Logo" width="300">
</p>

## תכונות עיקריות

### 🚀 Page Cache (קאש עמודים)
- קאש HTML מלא לעמודים
- תמיכה מלאה ב-WooCommerce (לא שומר עגלות/checkout)
- החרגות אוטומטיות לעמודים רגישים
- TTL מותאם אישית
- ניקוי אוטומטי בעדכון תוכן
- Debug log מובנה

### 📊 Query Profiler (מעקב שאילתות)
- מעקב אחר שאילתות איטיות
- זיהוי אינדקסים חסרים
- המלצות אופטימיזציה
- לוג שאילתות מפורט

### 🗄️ Database Optimization (אופטימיזציית מסד נתונים)
- ניקוי revisions ישנים
- ניקוי spam ו-trash
- ניקוי transients שפג תוקפם
- אופטימיזציית טבלאות
- הוספת אינדקסים חסרים

### ⚡ Performance Modules (מודולי ביצועים)

| מודול | תיאור |
|-------|--------|
| **Cart Fragments** | אופטימיזציה/השבתה של AJAX העגלה |
| **Heartbeat Control** | בקרת WordPress Heartbeat API |
| **WC Sessions Cleanup** | ניקוי אוטומטי של sessions |
| **Transients Cleanup** | ניקוי אוטומטי של transients |
| **Lazy Loading** | טעינה עצלה לתמונות ו-iframes |
| **DNS Prefetch** | טעינה מוקדמת של DNS לדומיינים חיצוניים |
| **Browser Caching** | כללי .htaccess לקאש בדפדפן |
| **Email Queue** | תור מיילים לשליחה ברקע |

## התקנה

### התקנה ידנית
1. הורד את התוסף
2. העלה את תיקיית `wc-speedup` ל-`/wp-content/plugins/`
3. הפעל את התוסף דרך תפריט 'תוספים' בוורדפרס

### דרישות
- WordPress 5.0 ומעלה
- WooCommerce 5.0 ומעלה
- PHP 7.4 ומעלה

## שימוש

### דשבורד ראשי
נווט ל-**WooHoo** בתפריט הניהול לגישה לדשבורד הראשי.

### Page Cache
1. עבור ל-**WooHoo → Page Cache**
2. הפעל את הקאש
3. הגדר TTL (זמן תפוגה)
4. הוסף החרגות לפי הצורך

#### החרגות מובנות:
- `/cart` - עמוד עגלה
- `/checkout` - עמוד תשלום
- `/my-account` - אזור אישי
- `/wishlist` - רשימת משאלות
- `/wp-json/` - REST API
- קבצים סטטיים (JS, CSS, תמונות)

#### הוספת החרגות מותאמות:
```
/he/contact/
/special-page/*
*.pdf
```

### Performance Modules
1. עבור ל-**WooHoo → Performance**
2. הפעל/כבה מודולים לפי הצורך
3. הגדר אפשרויות לכל מודול
4. לחץ "שמור הגדרות"

## תיאור מודולים

### Cart Fragments Optimizer
WooCommerce שולח בקשת AJAX בכל טעינת דף לעדכון העגלה. זה יכול להאט משמעותית את האתר.

**מצבים זמינים:**
- **טעינה מושהית** - טוען רק אחרי אינטראקציה של המשתמש (מומלץ)
- **מושבת לחלוטין** - מבטל את הבקשה לגמרי
- **מותאם עם localStorage** - משתמש בקאש מקומי

### Heartbeat Control
WordPress Heartbeat API רץ ברקע ושולח בקשות AJAX כל 15-60 שניות.

**הגדרות:**
- **בחזית האתר** - מומלץ להשבית
- **בממשק ניהול** - מומלץ להאט
- **בעורך תוכן** - השאר ברירת מחדל (נדרש לשמירה אוטומטית)

### WC Sessions Cleanup
WooCommerce שומר sessions במסד הנתונים שיכולים להצטבר לאלפי רשומות.

**אפשרויות:**
- ניקוי אוטומטי יומי
- בחירת גיל sessions למחיקה (3/7/14/30 ימים)
- ניקוי ידני בלחיצת כפתור

### Transients Cleanup
WordPress שומר transients (קאש זמני) בטבלת options. Transients שפג תוקפם נשארים במסד הנתונים.

**אפשרויות:**
- ניקוי אוטומטי יומי
- ניקוי ידני בלחיצת כפתור

### Lazy Loading
טעינה עצלה משפרת את זמן הטעינה הראשוני על ידי טעינת תמונות רק כשהן נראות.

**אפשרויות:**
- תמונות
- iframes (סרטונים, מפות)
- החרגת תמונות מעל הקיפול (above the fold)

### DNS Prefetch
טעינה מוקדמת של DNS לדומיינים חיצוניים מאיצה את טעינת המשאבים.

**אפשרויות:**
- זיהוי אוטומטי של דומיינים
- הוספת דומיינים ידנית

### Browser Caching
הגדרת Cache-Control headers מאפשרת לדפדפן לשמור קבצים מקומית.

**אפשרויות:**
- זמן קאש ל-CSS & JavaScript
- זמן קאש לתמונות
- יצירת כללי .htaccess אוטומטית

### Email Queue
תור מיילים מונע עיכובים בתהליך ההזמנה על ידי שליחת מיילים ברקע.

**אפשרויות:**
- גודל אצווה לשליחה
- עיבוד ידני של התור

## API & Hooks

### Filters

```php
// הוספת משאבים ל-preload
add_filter('wcsu_preload_resources', function($preloads) {
    $preloads['fonts'][] = 'https://example.com/font.woff2';
    return $preloads;
});
```

### Actions

```php
// ניקוי קאש לאחר עדכון מוצר
do_action('wcsu_clear_product_cache', $product_id);
```

## Troubleshooting (פתרון בעיות)

### הקאש לא עובד
1. ודא שתיקיית `/wp-content/cache/wcsu-page-cache/` קיימת וניתנת לכתיבה
2. בדוק את Debug Log בעמוד Page Cache
3. ודא שאתה בודק מחלון אינקוגניטו (לא מחובר)

### Wishlist לא עובד עם קאש
התוסף תומך ב-wishlist דרך JavaScript. ודא שתוסף ה-Wishlist שלך מעדכן את המצב בצד הלקוח.

### שגיאות בשמירת הגדרות
1. ודא ש-AJAX עובד באתר
2. בדוק את console הדפדפן לשגיאות JavaScript
3. ודא שיש לך הרשאות מנהל

## תאימות

### תוספי קאש
מומלץ לא להשתמש ב-page cache של תוסף אחר במקביל.

### תוספי Wishlist נתמכים
- EL Wishlist
- YITH WooCommerce Wishlist
- TI WooCommerce Wishlist

### CDN
התוסף תואם לשימוש עם CDN. ה-page cache שומר HTML בלבד.

## Changelog

### 1.1.0
- הוספת 8 מודולי ביצועים חדשים
- עמוד Performance חדש עם שליטה במודולים
- תיקון בעיות wishlist עם קאש
- שיפור ממשק ניהול

### 1.0.0
- גרסה ראשונית
- Page Cache עם תמיכת WooCommerce
- Query Profiler
- Database Optimization
- אופטימיזציות בסיסיות

## רישיון

GPL v2 or later

## תמיכה

לדיווח על באגים או בקשות תכונות:
https://github.com/elpeer/woocommerce-speedup/issues

## קרדיטים

נבנה על ידי ElPeer
