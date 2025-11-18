# NCMEC Toolkit for XenForo 2

## Overview

Electronic Service Providers (ESP) are required to report instances of child sexual exploitation material (CSEM) to the National Center for Missing and Exploited Children (NCMEC) under [18 U.S. Code § 2258A](https://www.law.cornell.edu/uscode/text/18/2258A).

XenForo 2 lacks a robust toolkit for dealing with sophisticated predators intending to propagate CSEM. This is a serious threat to service providers. This extension provides comprehensive tools for compliance with US federal law, robust anti-spam mechanisms, and urgent response capabilities to protect your community from child abuse material.

The United States Internet Preservation Society provides this code under the MIT license and it is free to use. We have no affiliation with the NCMEC or XenForo.

**Requirements:** NCMEC ESP credentials for report submission.

## Features

### Emergency Reporting System
- **User-initiated emergency reports**: Allows users to create emergency reports for suspected CSEM, triggering immediate moderation and staff notification
- **Automatic content moderation**: Reported content is automatically hidden from public view pending review
- **Thread-level moderation**: When a post is reported, the entire thread can be automatically moderated to prevent further exposure
- **Enhanced staff notifications**: Emergency reports trigger priority notifications to moderators with elevated urgency

### Approval Queue Integration
- **Flag CSAM action**: Adds a "Flag as CSAM" action to the moderation approval queue for posts, threads, profile posts, and profile post comments
- **Direct NCMEC flagging**: Moderators can flag content directly from the approval queue, automatically creating incidents and preparing for NCMEC reporting
- **Attachment preview**: View attachments associated with flagged content directly in the approval interface

### Report Center Integration
- **Report state flagging**: Moderators can resolve reports with the "Flag as CSAM" option directly from the report interface
- **Automatic incident creation**: Flagging a report creates or updates an incident, associating the reported content and user
- **Time-based content collection**: Automatically collects and associates all content posted by the reported user within a configurable time window (based on the default timespan option)
- **Cascading report closure**: All other open reports for the same content are automatically closed with the same CSAM flag message and incident reference
- **Unified workflow**: Provides the same functionality as approval queue flagging but integrated into the normal report handling workflow

### Incident Management System
- **Incident tracking**: Create and manage investigation incidents for suspected CSEM cases
- **User association**: Track users involved in incidents with automatic flagging system
- **Content aggregation**: Collect and associate all relevant content (posts, threads, attachments) with incidents
- **Bulk content operations**: Add content in bulk or perform fine-tuned selection during investigations
- **User content selector**: Quickly review and select a user's content to add to an incident

### User Flagging & Permissions
- **Automatic user field**: Users added to incidents receive a special `usips_ncmec_in_incident` field flag
- **Instant user upgrades**: The flag enables automatic user group promotions to apply instantly, allowing permission changes during active investigations
- **Investigation-based permissions**: Use the incident flag in user group promotion criteria to automatically adjust permissions (e.g., restrict posting, require approval, etc.)
- **Automatic promotion updates**: User promotions are automatically recalculated when users are added to or removed from incidents

### NCMEC Report Creation & Submission
- **Report builder**: Create detailed CyberTipline reports with all required NCMEC fields
- **Person management**: Track and store information about:
  - Points of contact in your organization
  - Law enforcement contacts
  - Known victims
  - Suspected perpetrators
- **Attachment handling**: Associate attachment data with incidents and reports
- **API integration**: Submit reports directly to NCMEC via their CyberTipline Reporting API
- **Report archiving**: Maintain records of all submitted reports

### Administrative Interface
- **Incident dashboard**: Overview of all active and finalized incidents
- **Attachment lookup**: Search for and locate specific attachments across your site
- **Attachment listing**: View recently uploaded attachments with filtering capabilities
- **Content preview**: Preview flagged content and attachments within the admin panel
- **Batch operations**: Perform bulk actions on users and content associated with incidents

### Audit & Compliance
- **API logging**: All NCMEC API interactions are logged for compliance tracking
- **Incident history**: Complete audit trail of incident creation, modifications, and report submissions
- **User association tracking**: Track when users are added to or removed from incidents
- **Content association tracking**: Track all content added to incidents with timestamps

## Installation

1. Upload the extension files to `src/addons/USIPS/NCMEC/`
2. Install the add-on through the XenForo admin panel
3. Configure NCMEC API credentials in the add-on options
4. Set appropriate admin permissions for NCMEC management

## Configuration

### Admin Permissions
Grant the `usips_ncmec` admin permission to staff members who should have access to incident management and NCMEC reporting tools.

### Options
Configure the following in the add-on options:
- NCMEC API endpoint and credentials
- Emergency report notification settings
- User field configuration for incident tracking

### User Group Promotions
Create user group promotions using the `usips_ncmec_in_incident` user field criteria to automatically adjust permissions for users under investigation.

## Usage

### For End Users
Users can report suspected CSEM using the standard report function with emergency handling enabled, which will immediately hide the content and notify staff.

### For Moderators
1. **Via Approval Queue**: Review flagged content in the approval queue and use the "Flag as CSAM" action to create an incident
2. **Via Report Center**: 
   - View any report for potentially harmful content
   - Select "Flag as CSAM" from the report state dropdown
   - Add a comment (optional) explaining the action
   - Submit to automatically flag content, create/update incident, and close related reports
3. Content is automatically added to the incident for investigation

### For Administrators
1. Access NCMEC tools via the admin panel navigation
2. Manage incidents and add relevant users/content
3. Build NCMEC reports from incident data
4. Submit reports to NCMEC CyberTipline
5. Track submitted reports and maintain compliance records

## Technical Notes

- Incidents can be created manually or automatically through the flagging system
- Users are automatically unflagged when removed from all incidents
- User promotions are recalculated in real-time when incident associations change
- All moderation actions are logged and auditable
- The system is designed to work with XenForo's existing content types and can be extended to support custom content types

## Support

This is open-source software provided as-is under the MIT license. For issues, feature requests, or contributions, please contact the United States Internet Preservation Society.

## Legal Disclaimer

This tool is designed to assist with compliance but does not constitute legal advice. Service providers should consult with legal counsel regarding their obligations under 18 U.S. Code § 2258A and related laws.