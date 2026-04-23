# SafetyFlash Application

## Overview

SafetyFlash is a modern web-based application for creating, managing, and publishing safety-related communications.

The system provides a centralized, structured, and traceable platform for safety communication across the organization.

Supported SafetyFlash types:
- First Release (Ensitiedote)
- Dangerous Situation (Vaaratilanne)
- Investigation Report (Tutkintatiedote)

Investigation reports are always a continuation of previous events or can be created independently.

---

## Key Capabilities

- Structured safety communication workflow
- Visual SafetyFlash card generation
- Integrated review and approval process
- Real-time dashboard and analytics
- Direct publishing to display systems
- Full traceability and version control
- Automated distribution to worksite display systems (Xibo)

---

## SafetyFlash Lifecycle (End-to-End Process)

The SafetyFlash process follows a structured lifecycle from incident to distribution.

### 1. Event Occurs

- Incident or dangerous situation happens at worksite

---

### 2. Creation (Worksite)

- Worksite creates initial SafetyFlash
- Content includes:
  - Description
  - Images
  - Initial details

---

### 3. Supervisor Review

- Worksite supervisor reviews and approves content

---

### 4. Safety Team Review

- Safety team validates:
  - correctness
  - required actions
  - classification

---

### 5. Communication & Localization

- Communication team:
  - finalizes content
  - performs language review
  - creates language versions

---

### 6. Publishing & Distribution

- SafetyFlash is published:
  - inside application
  - via email
  - to worksite displays (Xibo)

---

### 7. Investigation (if required)

- Investigation report is created
- Uses same workflow
- Links back to original event

---

### Key Benefits

- Clear ownership at each stage
- Transparent process tracking
- All actions logged

---

## Core Features

### Creation & Content

#### Multi-step Creation Process

SafetyFlash is created in six steps:

1. Type selection  
2. Location and time  
3. Content  
4. Images  
5. Layout  
6. Preview and publishing  

#### Image Editing & Annotations

- Drawing tools
- Markers
- Blur tool (faces, license plates, sensitive data)
- Annotations embedded into final images

#### Image Enhancements

- Preview images generated automatically
- Separate preview and final versions
- Zoom functionality in preview and view

#### Additional Information

- Rich text editor
- Used when content exceeds card space
- Included in PDF reports

---

### Collaboration & Workflow

#### Comments & Notifications

- Users can comment on SafetyFlashes
- Comment activity is logged
- Users can subscribe/unsubscribe to notifications

#### Roles

- Admin
- Supervisor
- Safety Team
- Communication

#### Edit Locking

- Prevents simultaneous editing
- Avoids conflicts and data loss

#### Presets (Safety Team Control)

- Define:
  - display targets
  - required language versions

---

### Data & Analytics

#### Injury Tracking System

- Body part selection in form
- Structured data storage
- Used in dashboard and filtering

#### Interactive Dashboard

- Human body heatmap
- Clickable body parts
- Injury distribution chart
- Worksite filtering
- Time filtering
- Modal view for full history

#### Advanced List View

- Filter by original type
- Investigation shows original type
- Injury info visible in list

#### Worksite-based Filtering

- Default worksite selection
- Automatic filtering
- Supports large-scale environments

#### Version History

- All versions stored
- Investigation links to original
- Full traceability

---

### Reliability & Performance

#### Reliable Saving

- Progressive saving during editing
- Temporary file storage
- No dependency on single submit

#### Card Splitting

- Automatic split for long content
- Manual override:
  - Force 1 card
  - Force 2 cards
- Only for Investigation Reports

---

### Language Versions

- SafetyFlashes can be translated
- Language version inherits base content

---

### Notifications

- Email notifications for:
  - status changes
  - review requests
  - workflow transitions

---

### PWA Support

- Installable as app
- Mobile optimized UI
- Limited offline capability

---

## Xibo Integration

SafetyFlash includes integration with digital signage systems such as Xibo.

### Overview

- SafetyFlashes can be displayed on screens
- Content delivered via API
- Displays fetch content dynamically

---

### How It Works

1. SafetyFlash is published  
2. System prepares display-ready content  
3. Content exposed via API  
4. Xibo player fetches and displays  

---

### Key Features

- Playlist-based display system
- Individual slide duration control
- Automatic updates
- TTL (time-to-live)
- Worksite-specific filtering
- Worksite-specific targeting and scheduling

---

### Authentication

- API key-based access
- Device-specific authentication
- Request validation

---

### Content Format

- Title
- Images
- Duration
- Ordering index
- Metadata (date, worksite, type)

---

### Display Behavior

- Periodic polling
- Automatic updates
- TTL-based removal

---

### Typical Use Case

1. SafetyFlash is published  
2. Appears automatically on displays  
3. Employees receive updates in real time  

---

## System Architecture

### Backend
- PHP (>= 7.4, recommended 8.x)
- MySQL

### Frontend
- Vanilla JavaScript
- Responsive CSS

---

## Project Structure

```
/app/
    /controllers/
    /views/
    /cron/

/assets/
    /js/
    /css/
    /pages/

/uploads/
    /images/
    /processes/

/storage/
    /logs/

/temp/

index.php
config.php
manifest.php
sw.js.php
```

---

## Data Flow

User Input  
→ Temporary Storage  
→ Validation  
→ Final Save  
→ Rendering  
→ Publishing  

---

## Background Jobs (Cron)

- cleanup_old_jobs.php
- cleanup_temp_images.php
- preview generation scripts

Cron must be configured on server.

---

## Known Considerations

### Temporary Files

- Stored in `/uploads/processes/`
- Requires cron cleanup

---

### Image Persistence

- Must be stored in database
- Not session-dependent

---

### Performance

- Use SQL aggregation for dashboard
- Avoid heavy loops

---

## Development Status

### Completed

- Reliable saving system
- Card splitting
- Dashboard heatmap
- Injury tracking
- Image annotations
- Filtering

---

### Recommended Improvements

- Push notifications
- Performance optimization
- Advanced role workflows
- API integrations

---

## Security

- Email domain restrictions
- Input validation
- File upload protection
- Authentication system

---

## Database migrations

Schema changes are stored as SQL files in `/migrations`.

Run new migrations manually against the application database in filename order.
For this change set, run:

```sql
source migrations/2026_04_worksite_visibility.sql;
```

---

## Summary

SafetyFlash provides a structured and automated system for safety communication.

It enables:

- Clear lifecycle from incident to distribution
- Full traceability and auditability
- Centralized collaboration within a single system
- Visual and standardized communication
- Direct publishing to worksite displays

The result is faster, clearer, and more reliable safety communication across the entire organization.
