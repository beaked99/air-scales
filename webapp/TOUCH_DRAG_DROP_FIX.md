# Touch-Enabled Drag & Drop + Live Data Implementation

## Date: 2026-01-20

## Changes Summary

### 1. Created Mobile-Optimized Base Template ✅

**File:** `templates/base_app.html.twig` (NEW)

Created separate base template for logged-in app pages with mobile optimizations:
- Proper viewport settings: `maximum-scale=5, user-scalable=yes`
- Mobile web app capabilities for iOS/Android
- Touch-optimized CSS:
  - Tap highlight reduction
  - Touch action manipulation
  - Smooth transitions for drag feedback
  - Disabled text selection during drag

**Why separate template?**
- Keeps `base.html.twig` clean for public-facing pages
- App-specific mobile optimizations don't affect homepage/login
- Better separation of concerns

---

### 2. Restored Drag-and-Drop with Touch Support ✅

**File:** `templates/configuration/edit.html.twig`

#### Device Card Drag-and-Drop

**UI Changes:**
- Restored drag handle with grip icon
- Added `draggable="true"` back to device cards
- Added CSS classes: `device-card`, `border-2`, `transition-all`
- Touch-optimized styling: `touch-none`, `-webkit-touch-callout: none`
- Added live data display area: `device-live-data-{deviceId}`

**JavaScript Implementation:**
```javascript
// Mouse events (desktop)
- dragstart → opacity 0.5
- dragover → blue border highlight
- drop → reorder DOM + save
- dragend → reset styles

// Touch events (mobile)
- touchstart → capture position, opacity 0.7, scale 1.02
- touchmove → detect vertical swipe (50px threshold)
  - Swipe up → move card up
  - Swipe down → move card down
  - Prevents scrolling during drag
  - Ignores horizontal swipes
- touchend → save order + reset styles
```

#### Channel Drag-and-Drop

**UI Changes:**
- Restored drag handles on each channel item
- Added `channel-item` class for targeting
- Live data displays on channels: `pressure-display`, `weight-display`
- Channel-specific CSS classes: `channel-live-data-{deviceId}-{channelIndex}`

**JavaScript Implementation:**
- Same touch/mouse event pattern as device cards
- Works within each device's channel list container
- Saves channel order per-device

---

### 3. Live Data Updates on Config Page ✅

**File:** `templates/configuration/edit.html.twig`

**New Function:** `initLiveDataUpdates()`

Listens to global BLE data events and updates:

**Device Cards:**
- Shows role + pressure + weight for slave devices
- Example: `slave • 120 psi • 5000 lbs`

**Individual Channels:**
- Updates pressure display (sky blue)
- Updates weight display (green)
- Format: `120 psi` and `5000 lbs`

**Data Flow:**
1. BLE data received via `window.AirScalesBLE` listener
2. Extract `device_id`, `mac_address`, channel data
3. Find matching DOM elements by device ID + channel index
4. Update text content with rounded values

---

### 4. Updated Template Inheritance ✅

**Files Changed:**
- `templates/configuration/edit.html.twig` → extends `base_app.html.twig`
- `templates/dashboard/index.html.twig` → extends `base_app.html.twig`

**Files NOT Changed (still use base.html.twig):**
- `templates/homepage/index.html.twig`
- `templates/security/login.html.twig`
- Other public-facing pages

---

## How Touch Drag-and-Drop Works

### For Device Cards:

1. **Start Drag:**
   - User touches drag handle
   - Card becomes slightly transparent (0.7) and scales up (1.02)
   - Touch position captured

2. **Dragging:**
   - User drags finger vertically
   - If movement > 50px up: card moves above previous card
   - If movement > 50px down: card moves below next card
   - Scrolling is prevented (e.preventDefault)
   - Horizontal swipes are ignored

3. **End Drag:**
   - User lifts finger
   - Card returns to normal opacity (1) and scale
   - New order is saved to backend
   - Success toast + page reload after 1 second

### For Channels:

Same pattern as device cards, but:
- Works within each device's channel list
- Supports both virtual steer and regular channels
- Saves per-device channel order

---

## Mobile Optimization Details

### Viewport Settings:
```html
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">
```
- Allows zoom up to 5x (accessibility)
- Prevents accidental zoom during touch

### Touch CSS:
```css
.touch-none {
  -webkit-user-select: none;
  user-select: none;
  -webkit-touch-callout: none;
}
```
- Prevents text selection during drag
- Disables iOS long-press context menu on drag handles

### Event Passive:
```javascript
{passive: false}
```
- Allows `preventDefault()` to block scrolling
- Required for touch-move events to prevent scroll

---

## Testing Checklist

### Desktop Testing:
- [ ] Open `/configuration/{id}/edit` on desktop browser
- [ ] Drag device cards with mouse - should reorder smoothly
- [ ] Drag channels within devices - should reorder
- [ ] Verify green success toast appears
- [ ] Verify page reloads showing new order

### Mobile Testing (Capacitor App):
- [ ] Open `/configuration/{id}/edit` on mobile
- [ ] Touch and drag device cards vertically
- [ ] Should see visual feedback (transparency, scale)
- [ ] Cards should swap positions as you drag
- [ ] Should NOT accidentally scroll page
- [ ] Touch and drag channels within devices
- [ ] Verify success toast appears
- [ ] Verify order persists after reload

### Live Data Testing:
- [ ] Connect to BLE device on mobile app
- [ ] Open `/configuration/{id}/edit`
- [ ] Verify pressure/weight displays on device cards update
- [ ] Verify pressure/weight on individual channels update
- [ ] Values should update in real-time as BLE data arrives

---

## Files Modified

1. ✅ `templates/base_app.html.twig` - NEW mobile-optimized base template
2. ✅ `templates/configuration/edit.html.twig` - Touch drag-and-drop + live data
3. ✅ `templates/dashboard/index.html.twig` - Use base_app.html.twig

---

## Technical Notes

### Why 50px Threshold?

The 50px swipe threshold prevents accidental reordering:
- Small finger movements don't trigger reorder
- Clear intentional drag required
- Reduces false positives

### Why Continuous Dragging?

The `touchStartY` is reset after each swap:
```javascript
touchStartY = touch.clientY; // Reset for continuous dragging
```

This allows smooth multi-position dragging without lifting finger.

### Why Prevent Scrolling?

During active drag:
```javascript
e.preventDefault(); // Prevent scrolling while dragging
```

This ensures drag gesture isn't confused with scroll gesture.

---

## Differences from Button Approach

| Button Approach | Drag-and-Drop Approach |
|----------------|------------------------|
| Tap up/down arrows | Touch and drag |
| One position at a time | Continuous multi-position |
| Visual clutter (2 buttons per item) | Clean single drag handle |
| Less intuitive on mobile | Natural gesture |
| Works but feels clunky | Smooth and fluid |

---

## Known Issues / Future Enhancements

1. **Long Lists:** If > 10 devices, consider scroll-aware drag-and-drop
2. **Haptic Feedback:** Could add vibration on swap for better tactile feedback
3. **Drag Preview:** Could show semi-transparent preview during drag
4. **Animation:** Could add smooth animation between positions

---

## Related Documentation

- See `FIXES_APPLIED.md` for displayOrder fixes
- See `MOBILE_REORDERING_FIX.md` for button approach (deprecated)
- Backend endpoints unchanged:
  - `POST /configuration/{id}/reorder-devices`
  - `POST /device/{id}/reorder-channels`
