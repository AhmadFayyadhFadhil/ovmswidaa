# 🔐 SECURITY VERIFICATION & DEPARTURE APPROVAL - IMPLEMENTATION PLANNING

**Date:** June 5, 2026  
**Scope:** Add Security verification layer before vehicle departure  
**Complexity Level:** High (Multi-tier workflow with approval chain)

---

## 📋 TABLE OF CONTENTS

1. [System Overview](#system-overview)
2. [Database Design](#database-design)
3. [Backend Architecture](#backend-architecture)
4. [Frontend Architecture](#frontend-architecture)
5. [API Endpoints](#api-endpoints)
6. [Role & Permission Changes](#role--permission-changes)
7. [Status Flow Diagram](#status-flow-diagram)
8. [Implementation Roadmap](#implementation-roadmap)
9. [Testing Strategy](#testing-strategy)

---

## 1. SYSTEM OVERVIEW

### Current Flow (Before)
```
1. Employee submits request
   ↓
2. Employee Management approves
   ↓
3. K.Dep HRD&GA assigns driver & vehicle
   ↓
4. Driver accepts assignment
   ↓
5. In Progress → Completed
```

### New Flow (After)
```
1. Employee submits request
   ↓
2. Employee Management approves
   ↓
3. K.Dep HRD&GA assigns driver & vehicle
   ↓
4. Driver accepts assignment
   ↓
5. ✨ NEW: Waiting Security Approval (NEW STATUS)
   ↓
6. ✨ NEW: Security verifies & approves departure (NEW ROLE)
   ↓
7. In Progress
   ↓
8. Completed
```

### New Stakeholders
- **Security Role** - Verifies vehicle readiness before departure
- **Security Dashboard** - View pending departure approvals
- **Security Approval** - Final gate before trip starts

---

## 2. DATABASE DESIGN

### 2.1 New Migrations Required

#### Migration 1: Add Security Role and Permissions
```php
// File: database/migrations/XXXX_XX_XX_000001_add_security_role_and_permissions.php

Permissions to create:
- 'view-pending-departures'
- 'approve-departure'
- 'add-departure-notes'
- 'view-all-requests'

Role to create:
- 'Security' (with above permissions)
```

#### Migration 2: Add Security Approval Columns to Requests
```php
// File: database/migrations/XXXX_XX_XX_000002_add_security_approval_to_requests_table.php

Columns to add:
- security_approved_at (timestamp, nullable)
- security_notes (text, nullable)
- security_verified_by (string, nullable) - For audit purposes
- departure_scheduled_at (timestamp) - Actual departure time
- security_check_date (date, nullable) - Date of security check
```

**Reasoning:**
- `security_approved_at` - Track when Security approved
- `security_notes` - Additional observations by Security
- `departure_scheduled_at` - Official departure time
- `security_check_date` - Date verification occurred

#### Migration 3: Create Security Approval Audit Table
```php
// File: database/migrations/XXXX_XX_XX_000003_create_security_approvals_table.php

Table: security_approvals
Columns:
- id (primary key)
- request_id (foreign key → requests)
- approved_at (timestamp)
- notes (text, nullable)
- status_before (string) - waiting_security_approval
- status_after (string) - in_progress
- created_at
- updated_at

Indexes:
- request_id (for fast lookups)
- approved_at (for audit queries)
```

**Reasoning:**
- Separate table for audit trail compliance
- Easy to query all approvals
- Maintains historical record

### 2.2 Model Updates

#### Update: Request Model
```php
// app/Models/Request.php

New Methods:
- securityApprovals() - Relationship to SecurityApproval
- canBeApprovedBySecurityNow() - Check if status is waiting_security_approval
- isAwaitingSecurityApproval() - Boolean check

New Attributes:
- security_approved_at
- security_notes
- departure_scheduled_at
- security_check_date

Casts:
- security_approved_at → datetime
- departure_scheduled_at → datetime
- security_check_date → date
```

#### New Model: SecurityApproval
```php
// app/Models/SecurityApproval.php

Properties:
- request_id (foreign)
- approved_at (timestamp)
- notes (text)
- status_before (string)
- status_after (string)

Relationships:
- request() → belongsTo(Request::class)

Methods:
- getStatusChangeDescription() → string
```

---

## 3. BACKEND ARCHITECTURE

### 3.1 New Controllers

#### Controller 1: SecurityDashboardController
```php
// app/Http/Controllers/Api/SecurityDashboardController.php

Methods:
- getPendingDepartures() → Get requests waiting for Security approval
  Input: pagination, filters (date, department, driver)
  Output: List of requests with assignment details

- show($requestId) → Get full details of specific request
  Input: request_id
  Output: Complete request + assignment + passenger info

- getAuditLog($requestId) → Get approval history
  Input: request_id
  Output: Array of all status changes

Filters:
- date_range (start_date, end_date)
- department_id
- driver_id
- vehicle_id
- status
```

#### Controller 2: DepartureApprovalController
```php
// app/Http/Controllers/Api/DepartureApprovalController.php

Methods:
- approveDeparture($requestId) → Approve and change status to in_progress
  Input: request_id, notes (optional)
  Output: Updated request with new status
  Validation:
    - Status must be waiting_security_approval
    - Request must have valid assignment
    - Request must have valid driver

- rejectDeparture($requestId) → Reject departure (stay at waiting_security_approval)
  Input: request_id, rejection_reason
  Output: Updated request, rejection recorded
  Validation:
    - Same as approve

- addVerificationNotes($requestId) → Add notes without approving
  Input: request_id, notes
  Output: Updated request with notes
```

### 3.2 New Actions

#### Action 1: ApproveDepartureAction
```php
// app/Actions/Approvals/ApproveDepartureAction.php

Responsibilities:
- Validate request status is waiting_security_approval
- Validate assignment exists and is valid
- Update request status to in_progress
- Record security_approved_at timestamp
- Save security_notes
- Create SecurityApproval audit record
- Trigger notifications to:
  - Driver (departure approved, can start)
  - Employee Management & K.Dep HRD&GA (monitoring)
  - Logger (audit trail)

Error Handling:
- InvalidStatusException (if not waiting_security_approval)
- InvalidAssignmentException (if assignment missing)
- AuthorizationException (if not Security role)
```

#### Action 2: CreateSecurityApprovalAuditAction
```php
// app/Actions/Approvals/CreateSecurityApprovalAuditAction.php

Responsibilities:
- Create SecurityApproval record
- Store all approval details
- Maintain audit trail
- Log to AuditLog table

Tracked Data:
- request_id
- approved_at timestamp
- notes
- status_before → 'waiting_security_approval'
- status_after → 'in_progress'
```

### 3.3 New Policies

#### Policy: SecurityApprovalPolicy
```php
// app/Policies/SecurityApprovalPolicy.php

Methods:
- viewPending(User $user) → Can user see pending approvals?
  Returns: true if user has Security role

- approveDeparture(User $user, Request $request) → Can approve this request?
  Returns: true if:
    - User has Security role
    - Request status is waiting_security_approval
    - Request has valid assignment

- addNotes(User $user, Request $request) → Can add verification notes?
  Returns: Same as approveDeparture
```

### 3.4 Observers & Events

#### Observer: DepartureApprovalObserver
```php
// app/Observers/DepartureApprovalObserver.php

Events to listen:
- created → Log creation in audit log
- updated → When security_approved_at changes, log it

Actions:
- Send notifications when status changes to in_progress
- Log all changes to AuditLog
```

---

## 4. FRONTEND ARCHITECTURE

### 4.1 New Components/Pages

#### Page 1: Security Dashboard
```javascript
// Component: SecurityDashboard.jsx / SecurityApprovalPage

Features:
- List of pending departures (waiting_security_approval)
- Filter by:
  - Date range
  - Department
  - Driver
  - Vehicle
- Search by:
  - Request ID
  - Employee name
  - Vehicle plate number

Data displayed in table:
- Request ID
- Employee name
- Department
- Purpose
- Pickup location
- Destination
- Departure date/time
- Number of passengers
- Vehicle assigned
- Driver assigned
- Current status
- Action button (View Details)

Sorting:
- By date (ascending/descending)
- By department
- By status
```

#### Page 2: Departure Detail View
```javascript
// Component: DepartureDetailModal.jsx / DepartureDetailPage

Sections:
1. Request Information
   - Request ID
   - Employee name & ID
   - Department
   - Purpose of trip
   - Notes

2. Trip Details
   - Pickup location
   - Destination
   - Departure date & time
   - Estimated return date & time
   - Number of passengers

3. Passenger List
   - Name
   - Department
   - ID (if available)

4. Assignment Details
   - Vehicle
     - Plate number
     - Type
     - Current condition (from VehicleObserver)
   - Driver
     - Name
     - License number
     - Contact
   - Assigned on (date/time)

5. Approval History (Timeline)
   - Employee request → submitted date
   - Employee Management approval → date & approver
   - K.Dep HRD&GA assignment → date & details
   - Driver acceptance → date
   - (NEW) Security verification → date & notes

6. Security Verification Section (NEW)
   - Input field for verification notes (optional)
   - Approve Departure button
   - Reject Departure button (alternative)

Actions available:
- Add verification notes
- Approve departure → Changes status to In Progress
- Reject departure (stay in Waiting Security Approval)
```

### 4.2 Security-Specific UI Elements

#### Component: DepartureApprovalCard
```javascript
// Reusable card showing departure info for quick view
- Vehicle info
- Driver info
- Departure time
- Quick action buttons (View Detail, Quick Approve)
```

#### Component: VerificationNotes
```javascript
// Text area for Security to add notes
- Character limit: 500
- Rich text: No (plain text only)
- Required: No (optional)
- Examples provided as placeholder
```

#### Component: ApprovalConfirmation
```javascript
// Modal confirmation before approval
- Shows request ID
- Asks for confirmation
- Allows adding notes before confirming
- Buttons: Confirm / Cancel
```

### 4.3 UI Flow

**Security Dashboard → View Departures**
```
┌─ Dashboard
│  - List of 10-15 pending approvals
│  - Filter & Search options
│  - Pagination
│
└─ Click "View Details"
   ├─ Departure Detail Modal opens
   │  - Shows all trip information
   │  - Shows assignment details
   │  - Shows approval history timeline
   │  - Notes input field
   │
   ├─ Security adds verification notes (optional)
   │
   └─ Click "Approve Departure"
      ├─ Confirmation modal appears
      ├─ Security confirms
      └─ Status changes to "In Progress"
         - Modal closes
         - Dashboard updates
         - Notifications sent
```

---

## 5. API ENDPOINTS

### 5.1 Security Dashboard Endpoints

#### GET /api/security/departures/pending
```
Purpose: Get list of pending departure approvals

Query Parameters:
- per_page (int, optional, default=15)
- page (int, optional, default=1)
- date_from (date, optional)
- date_to (date, optional)
- department_id (string, optional)
- driver_id (int, optional)
- vehicle_id (int, optional)
- search (string, optional) - search by request ID, employee name, plate

Response: 200 OK
{
  "data": [
    {
      "id": 10,
      "request_id": "REQ-001",
      "employee": {
        "id": 5,
        "name": "Ahmad Fayyadh",
        "email": "fadhil11@gmail.com",
        "nik": "12345678"
      },
      "department_id": "IT",
      "purpose": "Client visit",
      "pickup_location": "Office",
      "destination": "Jakarta",
      "departure_scheduled_at": "2026-06-06T08:00:00",
      "passenger_count": 2,
      "vehicle": {
        "id": 1,
        "plate_number": "B1234ABC",
        "type": "MPV",
        "brand": "Toyota"
      },
      "driver": {
        "id": 3,
        "name": "Jayadi",
        "phone": "081234567890",
        "license_number": "SIM123456"
      },
      "status": "waiting_security_approval",
      "submitted_at": "2026-06-05T10:00:00",
      "assignment_id": 5
    },
    ...
  ],
  "meta": {
    "total": 25,
    "per_page": 15,
    "current_page": 1,
    "last_page": 2
  }
}

Errors:
- 401 Unauthorized (not logged in)
- 403 Forbidden (not Security role)
```

#### GET /api/security/departures/{requestId}
```
Purpose: Get full details of a specific pending departure

Response: 200 OK
{
  "data": {
    "id": 10,
    "request_id": "REQ-001",
    "status": "waiting_security_approval",
    "employee": {...},
    "department_id": "IT",
    "purpose": "Client visit",
    "pickup_location": "Office",
    "destination": "Jakarta",
    "destination_city": "Jakarta",
    "destination_place": "Client Office",
    "departure_scheduled_at": "2026-06-06T08:00:00",
    "end_time": "2026-06-06T17:00:00",
    "passenger_count": 2,
    "passengers": [
      {
        "id": 1,
        "name": "Ahmad Fayyadh",
        "department_id": "IT"
      },
      {
        "id": 2,
        "name": "Budi Santoso",
        "department_id": "IT"
      }
    ],
    "vehicle": {
      "id": 1,
      "plate_number": "B1234ABC",
      "type": "MPV",
      "brand": "Toyota",
      "year": 2020,
      "color": "Silver",
      "current_status": "available"
    },
    "driver": {
      "id": 3,
      "name": "Jayadi",
      "phone": "081234567890",
      "license_number": "SIM123456",
      "license_expiry": "2026-12-31",
      "current_status": "available"
    },
    "assignment": {
      "id": 5,
      "status": "accepted",
      "assigned_at": "2026-06-05T14:00:00",
      "accepted_at": "2026-06-05T15:00:00"
    },
    "approval_history": [
      {
        "stage": "submitted",
        "status": "submitted",
        "completed_by": "Ahmad Fayyadh",
        "completed_at": "2026-06-05T10:00:00",
        "notes": null
      },
      {
        "stage": "employee_management",
        "status": "approved",
        "completed_by": "Manager HR",
        "completed_at": "2026-06-05T11:00:00",
        "notes": "Approved"
      },
      {
        "stage": "assignment",
        "status": "assigned",
        "completed_by": "Kepala Departemen HRD&GA",
        "completed_at": "2026-06-05T14:00:00",
        "vehicle_id": 1,
        "driver_id": 3,
        "notes": null
      },
      {
        "stage": "driver_acceptance",
        "status": "accepted",
        "completed_by": "Jayadi",
        "completed_at": "2026-06-05T15:00:00",
        "notes": null
      }
    ],
    "security_notes": null,
    "security_approved_at": null
  }
}

Errors:
- 401 Unauthorized
- 403 Forbidden (not Security role)
- 404 Not Found (request doesn't exist or not in waiting_security_approval)
```

#### GET /api/security/departures/{requestId}/audit-log
```
Purpose: Get complete audit log for a departure request

Response: 200 OK
{
  "data": [
    {
      "id": 1,
      "request_id": 10,
      "action": "submitted",
      "status_before": null,
      "status_after": "submitted",
      "performed_by": "Ahmad Fayyadh",
      "performed_at": "2026-06-05T10:00:00",
      "notes": "Request submitted"
    },
    {
      "id": 2,
      "request_id": 10,
      "action": "approved",
      "status_before": "submitted",
      "status_after": "approved_department",
      "performed_by": "Manager HR",
      "performed_at": "2026-06-05T11:00:00",
      "notes": "Approved"
    },
    ...
    {
      "id": 5,
      "request_id": 10,
      "action": "security_approval",
      "status_before": "waiting_security_approval",
      "status_after": "in_progress",
      "performed_by": "Security System",
      "performed_at": "2026-06-06T07:50:00",
      "notes": "Vehicle and driver verified. Ready for departure."
    }
  ]
}
```

### 5.2 Security Approval Endpoints

#### POST /api/security/departures/{requestId}/approve
```
Purpose: Approve departure and change status to in_progress

Request Body:
{
  "notes": "Vehicle condition good, driver ready. Approved for departure." (optional)
}

Response: 200 OK
{
  "status": "success",
  "message": "Departure approved successfully",
  "data": {
    "id": 10,
    "request_id": "REQ-001",
    "status": "in_progress",
    "security_approved_at": "2026-06-06T07:50:00",
    "security_notes": "Vehicle condition good, driver ready. Approved for departure.",
    "departure_scheduled_at": "2026-06-06T08:00:00"
  }
}

Errors:
- 400 Bad Request (invalid request data)
- 401 Unauthorized
- 403 Forbidden (not Security role)
- 404 Not Found
- 409 Conflict (status is not waiting_security_approval)
```

#### POST /api/security/departures/{requestId}/reject
```
Purpose: Reject departure (stay at waiting_security_approval)

Request Body:
{
  "rejection_reason": "Driver license expired, need replacement"
}

Response: 200 OK
{
  "status": "success",
  "message": "Departure rejected. Request remains in waiting_security_approval",
  "data": {
    "id": 10,
    "request_id": "REQ-001",
    "status": "waiting_security_approval",
    "security_notes": "REJECTION: Driver license expired, need replacement"
  }
}

Errors: (same as approve endpoint)
```

#### POST /api/security/departures/{requestId}/notes
```
Purpose: Add verification notes without approving/rejecting

Request Body:
{
  "notes": "Vehicle interior check completed. Fuel tank at 3/4 level."
}

Response: 200 OK
{
  "status": "success",
  "message": "Notes added successfully",
  "data": {
    "id": 10,
    "security_notes": "Vehicle interior check completed. Fuel tank at 3/4 level."
  }
}

Errors: (same as above)
```

---

## 6. ROLE & PERMISSION CHANGES

### 6.1 New Role: Security

```
Role Name: Security
Display Name: Security Officer
Description: Verifies vehicle readiness and approves departures

Permissions:
- view-pending-departures
  Description: Can view list of requests awaiting security approval
  
- approve-departure
  Description: Can approve departure and change status to in_progress
  
- add-departure-notes
  Description: Can add verification notes to departures
  
- view-all-requests
  Description: Can view all request details for verification
  
- view-vehicle
  Description: Can view vehicle details
  
- view-audit-log
  Description: Can view audit logs for compliance

Assignment:
- Can be assigned to individual Security officers
- Single login per company or multiple officers with same role?
  → Business decision needed (currently planning for flexible assignment)
```

### 6.2 Permission Matrix

```
                          | Employee | Driver | Approver | K.Dep HRD&GA | GA | Admin | Security
─────────────────────────┼──────────┼────────┼──────────┼──────────────┼────┼───────┼──────────
Create Request            |    ✓     |   ✗    |    ✗     |      ✗       | ✗  |   ✓   |    ✗
Approve Request           |    ✗     |   ✗    |    ✓     |      ✗       | ✗  |   ✓   |    ✗
View Own Requests         |    ✓     |   ✓    |    ✗     |      ✗       | ✗  |   ✓   |    ✗
View All Requests         |    ✗     |   ✗    |    ✓     |      ✓       | ✓  |   ✓   |    ✓
Create Assignment         |    ✗     |   ✗    |    ✗     |      ✓       | ✗  |   ✓   |    ✗
Update Assignment         |    ✗     |   ✓    |    ✓     |      ✓       | ✗  |   ✓   |    ✗
View Pending Departures   |    ✗     |   ✗    |    ✗     |      ✗       | ✗  |   ✓   |    ✓
Approve Departure         |    ✗     |   ✗    |    ✗     |      ✗       | ✗  |   ✓   |    ✓
Add Departure Notes       |    ✗     |   ✗    |    ✗     |      ✗       | ✗  |   ✓   |    ✓
```

---

## 7. STATUS FLOW DIAGRAM

### Request Status Lifecycle (NEW)

```
                    ┌─────────────────────────────────────────────────┐
                    │                                                 │
                    ▼                                                 │
            ┌──────────────┐                                          │
            │  Submitted   │                                          │
            └──────────────┘                                          │
                    │                                                 │
                    │ Employee Management approves                    │
                    ▼                                                 │
        ┌─────────────────────────┐                                  │
        │ Approved Department     │                                  │
        └─────────────────────────┘                                  │
                    │                                                 │
                    │ K.Dep HRD&GA assigns vehicle & driver          │
                    ▼                                                 │
        ┌─────────────────────────┐                                  │
        │ Driver Assigned         │                                  │
        └─────────────────────────┘                                  │
                    │                                                 │
                    │ Driver accepts assignment                       │
                    ▼                                                 │
    ┌─────────────────────────────────────┐                          │
    │ ✨ Waiting Security Approval (NEW) │◄─── Rejection ──┐        │
    └─────────────────────────────────────┘                 │        │
                    │                                       │        │
                    │ Security verifies & approves          │        │
                    │ departure                             │        │
                    ▼                                       │        │
            ┌──────────────────┐                           │        │
            │ ✨ In Progress   │                           │        │
            │     (NEW)        │                           │        │
            └──────────────────┘                           │        │
                    │                                       │        │
                    │ Trip completes                        │        │
                    ▼                                       │        │
            ┌──────────────────┐                           │        │
            │    Completed     │                           │        │
            └──────────────────┘                           │        │
                                                            │        │
                                                            └────────┘
```

### Alternative Flows

**If Security Rejects:**
```
Waiting Security Approval
      ↓
   REJECT
      ↓
Waiting Security Approval (stays)
      ↓
K.Dep HRD&GA can reassign or modify
      ↓
Re-submit to Security
```

---

## 8. IMPLEMENTATION ROADMAP

### Phase 1: Database & Models (Week 1)
- [ ] Create migrations for:
  - Security role and permissions
  - Add columns to requests table
  - Create security_approvals table
- [ ] Update Request model with new methods
- [ ] Create SecurityApproval model
- [ ] Run migrations and test

**Deliverable:** Database schema ready, models compiled without errors

### Phase 2: Backend API (Week 2)
- [ ] Create SecurityDashboardController with methods:
  - getPendingDepartures()
  - show()
  - getAuditLog()
- [ ] Create DepartureApprovalController with methods:
  - approveDeparture()
  - rejectDeparture()
  - addNotes()
- [ ] Create Actions:
  - ApproveDepartureAction
  - CreateSecurityApprovalAuditAction
- [ ] Create Policy: SecurityApprovalPolicy
- [ ] Create Observer: DepartureApprovalObserver
- [ ] Update routes in routes/api.php
- [ ] Add permissions to database seeder

**Deliverable:** All API endpoints functional, tested with Postman

### Phase 3: Frontend UI (Week 3)
- [ ] Create Security Dashboard component
- [ ] Create Departure Detail modal
- [ ] Create verification notes input
- [ ] Create approval confirmation modal
- [ ] Integrate with AuthContext for Security role
- [ ] Add navigation to Security dashboard from main menu
- [ ] Style components consistently

**Deliverable:** Frontend UI complete, integrated with backend

### Phase 4: Notifications (Week 4)
- [ ] Implement notification system for:
  - Driver notified when departure approved
  - Security notified when waiting approval
  - Employee Management notified when in_progress
- [ ] Add toast notifications in frontend
- [ ] Add email notifications (optional, if email system exists)
- [ ] Add in-app notification bell icon

**Deliverable:** Notifications working end-to-end

### Phase 5: Testing & QA (Week 5)
- [ ] Unit tests for Actions
- [ ] Integration tests for API endpoints
- [ ] E2E tests for complete workflow
- [ ] Security role permission tests
- [ ] Edge case testing
- [ ] Performance testing with large datasets
- [ ] UAT with Security team

**Deliverable:** All tests passing, system ready for production

### Phase 6: Deployment & Documentation (Week 6)
- [ ] Deployment to staging
- [ ] User training materials
- [ ] API documentation
- [ ] Database backup procedures
- [ ] Rollback plan
- [ ] Production deployment

**Deliverable:** System live, users trained, documentation complete

---

## 9. TESTING STRATEGY

### 9.1 Unit Tests

**File:** `tests/Unit/Actions/ApproveDepartureActionTest.php`
```php
✓ Test successful approval (status changes to in_progress)
✓ Test invalid status throws exception
✓ Test missing assignment throws exception
✓ Test security_approved_at timestamp set correctly
✓ Test security_notes saved correctly
✓ Test audit record created
✓ Test unauthorized user rejected
```

**File:** `tests/Unit/Policies/SecurityApprovalPolicyTest.php`
```php
✓ Test Security role can approve departures
✓ Test non-Security roles cannot approve
✓ Test request must be in waiting_security_approval status
✓ Test request must have valid assignment
```

### 9.2 Integration Tests

**File:** `tests/Feature/SecurityDashboardTest.php`
```php
✓ Test get pending departures list
✓ Test pagination works
✓ Test filters work (date, department, driver)
✓ Test search functionality
✓ Test response includes all required fields
✓ Test unauthorized access denied
```

**File:** `tests/Feature/DepartureApprovalTest.php`
```php
✓ Test approve departure endpoint
✓ Test rejection endpoint
✓ Test add notes endpoint
✓ Test status changes correctly
✓ Test audit record created
✓ Test notifications triggered
✓ Test validation rules enforced
```

### 9.3 E2E Tests

**File:** `tests/E2E/SecurityWorkflowTest.php`
```php
Scenario 1: Complete Approval Flow
✓ Employee submits request
✓ Employee Management approves
✓ K.Dep HRD&GA assigns driver & vehicle
✓ Driver accepts assignment
✓ Security sees request in pending list
✓ Security views details
✓ Security adds notes
✓ Security approves departure
✓ Status changes to in_progress
✓ Notifications sent
✓ Audit log updated

Scenario 2: Rejection Flow
✓ Same as above until approval
✓ Security rejects instead
✓ Status stays waiting_security_approval
✓ K.Dep can reassign and resubmit
✓ Audit log shows rejection

Scenario 3: Access Control
✓ Employee cannot access Security dashboard
✓ Driver cannot access Security dashboard
✓ Approver cannot approve departure (only K.Dep & Security)
✓ Only Security can approve departures
```

### 9.4 Test Data Requirements

```
Test Users:
- 1 Security user (security@ovms.test)
- 1 K.Dep HRD&GA (kepala.hrdga@company.com) - already exists
- 1 Employee Management (manager@ovms.test)
- 1 Driver (driver@ovms.test) - already exists
- 1 Employee (employee@ovms.test) - already exists

Test Requests:
- 5 requests in various statuses for testing

Test Vehicles:
- 3 vehicles (already exist)

Test Assignments:
- 3 assignments ready for approval
```

---

## 10. API SECURITY CONSIDERATIONS

### Authentication
- All endpoints require Bearer token (already implemented)
- Token validated before processing

### Authorization
- Security role checked via Policy
- Check user has 'Security' role
- Check request status is exactly 'waiting_security_approval'

### Input Validation
- Request body validated
- Notes field: max 500 characters, plain text only
- Request ID validated exists and belongs to correct status

### Rate Limiting
- Consider rate limiting on approval endpoints (prevent spam)
- Example: 100 approvals per hour per user

### Audit Trail
- All approvals logged to audit log
- Cannot delete audit records
- Timestamps immutable

---

## 11. BUSINESS RULES CHECKLIST

```
✓ In Progress status only after Security approval
✓ Cannot start trip without Security approval
✓ Approval only for waiting_security_approval status
✓ Security dashboard only shows assigned requests
✓ All status changes logged
✓ One Security role (business decision: shared vs multiple users)
✓ K.Dep cannot approve own assignments (Security handles it)
✓ Assignment changes blocked once in waiting_security_approval
```

---

## 12. DOCUMENTATION REQUIREMENTS

### For Developers
- [ ] API documentation (OpenAPI/Swagger)
- [ ] Code comments for complex logic
- [ ] Database schema diagram
- [ ] ER diagram for new tables

### For Users
- [ ] Security role user manual
- [ ] Step-by-step approval process guide
- [ ] Screenshots of dashboard
- [ ] Troubleshooting guide

### For Operations
- [ ] Deployment checklist
- [ ] Backup procedures
- [ ] Monitoring guide
- [ ] Rollback procedures

---

## 13. RISKS & MITIGATION

### Risk 1: High Volume of Pending Approvals
**Risk:** Security overwhelmed with many pending requests  
**Mitigation:**
- Implement smart filtering
- Show most urgent first
- Set daily approval limits if needed
- Multiple Security users can be assigned

### Risk 2: System Downtime During Approval
**Risk:** If system down, vehicle cannot depart  
**Mitigation:**
- Implement offline approval mechanism (optional)
- Have backup manual process
- Ensure high availability

### Risk 3: Data Inconsistency
**Risk:** Approval partially processed if error occurs  
**Mitigation:**
- Use database transactions
- Implement rollback on errors
- Test error scenarios thoroughly

### Risk 4: Security User Overload
**Risk:** Single Security user becomes bottleneck  
**Mitigation:**
- Allow multiple Security role users
- Implement notification system
- Track approval times for monitoring

---

## 14. SUCCESS METRICS

After implementation, measure:

1. **Efficiency**
   - Average approval time: Target < 5 minutes
   - Pending queue size: Target < 10 at peak times
   
2. **Reliability**
   - System uptime: Target 99.9%
   - Error rate: Target < 0.1%
   
3. **Compliance**
   - 100% of trips have security approval before departure
   - 100% of approvals logged in audit trail
   
4. **User Satisfaction**
   - Security user satisfaction: > 4/5
   - K.Dep HRD&GA satisfaction: > 4/5

---

## 15. NEXT STEPS

1. **Get Approval** - Review this planning with stakeholders
2. **Clarify Business Logic** - Confirm:
   - Single or multiple Security users?
   - Manual rejection allowed or auto-reject?
   - Notification preferences?
3. **Create Detailed Specification** - From this planning
4. **Begin Phase 1** - Database and models
5. **Weekly Reviews** - Check progress against roadmap

---

**Planning Document Version:** 1.0  
**Last Updated:** June 5, 2026  
**Status:** Ready for Implementation  
**Next Review:** After stakeholder approval
