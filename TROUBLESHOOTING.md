# Payment Icons Troubleshooting

## Issue: Payment Icons Appear Too Large

If you're using Elementor or other page builders and the payment icons appear oversized on your checkout page, add this CSS to fix the display:

### Solution 1: Elementor Custom CSS
1. Go to **Elementor > Custom CSS** (or your theme's custom CSS)
2. Add this code:

```css
/* Fix payment icons sizing in Elementor */
.elementor .hcwc-card-icon {
    height: 32px !important;
    width: auto !important;
    max-height: 32px !important;
    object-fit: contain !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .elementor .hcwc-card-icon {
        height: 28px !important;
        max-height: 28px !important;
    }

}
```

### Solution 2: WordPress Theme Customizer
1. Go to **Appearance > Customize > Additional CSS**
2. Paste the same CSS code above

### Solution 3: Theme CSS File
Add the CSS code to your active theme's `style.css` file (or child theme's `style.css`).

---

## Other Page Builders

For **Visual Composer**, **Beaver Builder**, or other page builders, replace `.elementor` with the appropriate class:

- Visual Composer: `.vc_row .hcwc-card-icon`
- Beaver Builder: `.fl-builder .hcwc-card-icon`
- Gutenberg Blocks: `.wp-block-column .hcwc-card-icon`

## Need Help?

If you continue to experience issues, please provide:
1. Your WordPress theme name
2. Page builder (if any)
3. A screenshot of the oversized icons

This helps us provide more specific guidance.
