---
name: Core Efficiency
colors:
  surface: '#faf8ff'
  surface-dim: '#d9d9e4'
  surface-bright: '#faf8ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f3fd'
  surface-container: '#ededf8'
  surface-container-high: '#e7e7f2'
  surface-container-highest: '#e1e2ec'
  on-surface: '#191b23'
  on-surface-variant: '#434654'
  inverse-surface: '#2e3038'
  inverse-on-surface: '#f0f0fb'
  outline: '#737685'
  outline-variant: '#c3c6d6'
  surface-tint: '#0c56d0'
  primary: '#003d9b'
  on-primary: '#ffffff'
  primary-container: '#0052cc'
  on-primary-container: '#c4d2ff'
  inverse-primary: '#b2c5ff'
  secondary: '#006c47'
  on-secondary: '#ffffff'
  secondary-container: '#82f9be'
  on-secondary-container: '#00734c'
  tertiary: '#5e3c00'
  on-tertiary: '#ffffff'
  tertiary-container: '#7d5200'
  on-tertiary-container: '#ffca81'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dae2ff'
  primary-fixed-dim: '#b2c5ff'
  on-primary-fixed: '#001848'
  on-primary-fixed-variant: '#0040a2'
  secondary-fixed: '#82f9be'
  secondary-fixed-dim: '#65dca4'
  on-secondary-fixed: '#002113'
  on-secondary-fixed-variant: '#005235'
  tertiary-fixed: '#ffddb3'
  tertiary-fixed-dim: '#ffb950'
  on-tertiary-fixed: '#291800'
  on-tertiary-fixed-variant: '#624000'
  background: '#faf8ff'
  on-background: '#191b23'
  surface-variant: '#e1e2ec'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  display-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  label-sm:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '500'
    lineHeight: 14px
  display-md-mobile:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 8px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  container-margin: 24px
  column-gutter: 16px
---

## Brand & Style
The design system is engineered for high-velocity CRM and conversation management environments. It prioritizes clarity, trust, and professional efficiency, ensuring that users can process large volumes of data without cognitive fatigue. 

The aesthetic is **Corporate / Modern**, characterized by a systematic approach to whitespace, a refined color palette, and a functional information hierarchy. It avoids decorative excess in favor of utility, using subtle depth and precise alignment to create an interface that feels reliable and organized. The target response is a sense of "organized control" where critical customer insights and communication threads are immediately accessible.

## Colors
The color strategy employs a "Signal & Surface" approach. 
- **Primary Blue (#0052CC):** Used for core actions, navigation states, and primary identifiers.
- **Success Green (#36B37E):** Reserved for positive growth metrics, "Resolved" statuses, and successful system confirmations.
- **Warning Orange (#FFAB00):** Applied to "Pending" states, urgent notifications, or at-risk customer accounts.
- **Neutrals:** A range of cool grays provides the structural foundation. Backgrounds utilize a very light gray (`#F4F5F7`) to differentiate the UI surface from the white (`#FFFFFF`) card containers.

## Typography
This design system uses **Inter** exclusively to ensure a systematic, utilitarian feel across all platforms. The scale is built on a tight 4px baseline. 
- **Headlines:** Use tighter letter spacing and semi-bold/bold weights to anchor page sections.
- **Body:** Standardized at 14px for optimal density in data-heavy CRM screens, with 16px reserved for long-form conversation threads.
- **Labels:** Uppercase styles with increased letter spacing are used for table headers and small categorizations to distinguish them from interactive data.

## Layout & Spacing
The system follows a strict **8px Grid** for all spatial relationships. 
- **Grid Model:** A 12-column fluid grid is used for the main dashboard content area.
- **Sidebars:** Navigation is fixed at 240px (expanded) or 64px (collapsed).
- **Margins:** 24px horizontal padding on desktop, reducing to 16px on mobile.
- **Reflow:** On tablet, 3-column metric cards stack into 2-column layouts; on mobile, all elements transition to a single-column flow with full-width cards.

## Elevation & Depth
Depth is used sparingly to signify interactivity and container boundaries.
- **Level 0 (Background):** `#F4F5F7` - The canvas on which all elements sit.
- **Level 1 (Cards/Surface):** `#FFFFFF` with a 1px border of `#DFE1E6`.
- **Level 2 (Interactive/Floating):** Use a subtle ambient shadow (0px 4px 8px rgba(9, 30, 66, 0.08)) for cards that are hovered or for secondary navigation menus.
- **Dividers:** 1px solid lines are preferred over shadows for internal card divisions and table rows to maintain a "flat professional" appearance.

## Shapes
The shape language is "Soft Professional." 
- **Base Components:** Buttons and input fields use an 8px radius.
- **Containers:** Dashboard cards and large modals use a 12px (`rounded-lg`) radius to create a distinct frame for content.
- **Indicators:** Status tags and "pills" use a fully rounded (32px+) radius to differentiate them from interactive buttons.

## Components
- **Metric Cards:** Large `display-md` values with `body-sm` labels. Trend indicators (up/down arrows) use Success/Warning colors.
- **Data Tables:** High-density layout. Headers use `label-md` with a subtle gray background. Rows alternate with a hover state change to `#F4F5F7`.
- **Conversation UI:** Left-aligned (customer) and right-aligned (agent) bubbles. Agent bubbles use the Primary Blue; customer bubbles use a light neutral gray.
- **Buttons:** 
  - *Primary:* Solid Primary Blue, white text.
  - *Secondary:* Ghost style with Primary Blue border and text.
- **Inputs:** 1px border of `#DFE1E6`, changing to `#0052CC` on focus. Placeholder text in `#7A869A`.
- **Charts:** Use a palette of Primary Blue and its tints (60%, 40%, 20%) for multi-series data to maintain brand consistency.