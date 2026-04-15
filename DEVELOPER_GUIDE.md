{\rtf1\ansi\ansicpg1252\cocoartf2868
\cocoatextscaling0\cocoaplatform0{\fonttbl\f0\fswiss\fcharset0 Helvetica;\f1\fnil\fcharset0 LucidaGrande;}
{\colortbl;\red255\green255\blue255;}
{\*\expandedcolortbl;;}
\paperw11900\paperh16840\margl1440\margr1440\vieww11520\viewh8400\viewkind0
\pard\tx720\tx1440\tx2160\tx2880\tx3600\tx4320\tx5040\tx5760\tx6480\tx7200\tx7920\tx8640\pardirnatural\partightenfactor0

\f0\fs24 \cf0 # Developer Guide \'96 SafetyFlash\
\
## Overview\
\
SafetyFlash is a modular PHP-based system for managing safety communication workflows, image-based reporting, and analytics.\
\
---\
\
## Architecture\
\
Structure:\
\
- Controllers 
\f1 \uc0\u8594 
\f0  logic\
- Views 
\f1 \uc0\u8594 
\f0  rendering\
- Assets 
\f1 \uc0\u8594 
\f0  frontend\
- Uploads 
\f1 \uc0\u8594 
\f0  files\
- Cron 
\f1 \uc0\u8594 
\f0  background tasks\
\
---\
\
## Page Structure\
\
Main application pages:\
\
- dashboard.php\
- form.php\
- form_language.php\
- list.php\
- view.php\
- feedback.php\
- profile.php\
- worksites.php\
- updates.php\
- playlist_manager.php\
\
---\
\
## Core Systems\
\
### Form System\
\
Handles:\
- Multi-step flow\
- Validation\
- Temporary saving\
- Image handling\
\
---\
\
### Temporary Storage\
\
Location:\
```\
/uploads/processes/\
```\
\
Used for:\
- Images\
- Intermediate data\
\
Purpose:\
- Prevent data loss\
\
---\
\
### Rendering Engine\
\
Responsible for:\
\
- Card generation\
- Layout logic\
- Image placement\
\
---\
\
### Card Splitting\
\
- Only for Investigation Reports\
- Based on content length\
- Supports manual override\
\
---\
\
### Dashboard Engine\
\
Handles:\
\
- Injury aggregation\
- Filtering\
- Visualization\
\
---\
\
### Injury Data\
\
Stored as structured entries:\
\
- Body parts\
- Linked to SafetyFlash\
\
Used in:\
- Dashboard\
- Filtering\
- Reports\
\
---\
\
### Image Processing\
\
Supports:\
\
- Upload\
- Annotation\
- Blur tool\
\
Important:\
- Must persist across edit sessions\
\
---\
\
### Additional Information\
\
- Rich text content\
- Included in PDF\
\
---\
\
## Data Flow\
\
User Input  \

\f1 \uc0\u8594 
\f0  Temp Storage  \

\f1 \uc0\u8594 
\f0  Validation  \

\f1 \uc0\u8594 
\f0  Save  \

\f1 \uc0\u8594 
\f0  Render  \

\f1 \uc0\u8594 
\f0  Publish  \
\
---\
\
## Background Jobs\
\
Located in:\
\
```\
/app/cron/\
```\
\
Includes:\
\
- Temp cleanup\
- Image cleanup\
- Preview generation\
\
Requires:\
Cron jobs must be enabled in the environment for cleanup and background processing.\
\
---\
\
## Debugging\
\
Check:\
\
- `/uploads/processes/`\
- Database image references\
- Rendering logic\
- Card split conditions\
\
---\
\
## Critical Areas\
\
### Image Persistence\
\
Ensure:\
- Stored in database\
- Not session-based\
\
---\
\
### Card Splitting\
\
Ensure:\
- Only active for Investigation Reports\
\
---\
\
### Dashboard Performance\
\
Use:\
- SQL aggregation\
\
Avoid:\
- Large PHP loops\
\
---\
\
## Future Development\
\
- Push notifications\
- Workflow automation\
- API integrations\
- Mobile improvements\
\
---\
\
## Summary\
\
The system is designed for reliability, scalability, and structured safety communication workflows with strong emphasis on data integrity and usability.}