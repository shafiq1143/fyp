# College Management System (CMS)

A comprehensive web-based College Management System built with React (frontend) and PHP (backend), designed to manage students, courses, attendance, marks, and administrative tasks.

## Features

### Student Management
- Student account creation and authentication
- Enrollment in courses
- Email notifications for:
  - Account creation
  - Marks updates
  - Assignment grading
  - Attendance marking

### Course Management
- Course creation and configuration
- Course offerings with date scheduling
- Assignment creation and management
- Grading system for assignments and exams

### Attendance Tracking
- Mark attendance (present, absent, late, excused)
- Automatic student email notifications
- Admin attendance reports

### Marks & Results
- Upload and manage student marks
- Final results calculation
- Grade tracking and reporting
- Email notifications to students

### Admin Dashboard
- User management
- Course oversight
- Attendance and marks administration
- System activity logging

### Teacher Dashboard
- Manage assigned courses
- Mark attendance
- Upload and grade assignments
- View class reports

### Student Dashboard
- View enrolled courses
- Check attendance
- View marks and grades
- Track assignments

## Technology Stack

### Backend
- **PHP 8.x** with built-in development server
- **MySQL** database
- **SMTP** email notifications (Gmail)
- RESTful API endpoints

### Frontend
- **React 18** with Vite bundler
- **JavaScript** (ES6+)
- **CSS3** for styling
- **Fetch API** for backend communication

## Project Structure

```
CMS/
├── backend/          # PHP API server
│   ├── config.php    # Database & SMTP configuration
│   ├── handlers.php  # Request handlers for all endpoints
│   ├── mailer.php    # Email sending utility
│   ├── bootstrap.php # Database initialization
│   ├── index.php     # Main entry point
│   └── uploads/      # File uploads directory
├── frontend/         # React application
│   ├── src/
│   │   ├── components/   # Reusable React components
│   │   ├── pages/        # Page components
│   │   ├── context/      # Context API for state management
│   │   ├── App.jsx       # Main app component
│   │   └── api.js        # API communication module
│   ├── package.json
│   └── vite.config.js    # Vite configuration
└── database/         # Database schema & setup
    └── schema.sql    # Database schema
```

## Setup Instructions

### Prerequisites
- PHP 8.0+ with PDO MySQL extension
- MySQL/MariaDB
- Node.js 16+ and npm
- XAMPP or similar local server (or use PHP built-in server)

### Backend Setup

1. **Configure Database**
   - Import `database/schema.sql` into your MySQL database
   - Update `backend/config.php` with database credentials

2. **Configure Email (Optional)**
   - Set up Gmail App Password in `backend/config.php`
   - Update SMTP settings:
     ```php
     'smtp_host' => 'smtp.gmail.com',
     'smtp_username' => 'your-email@gmail.com',
     'smtp_password' => 'your-app-password',
     'from_email' => 'your-email@gmail.com'
     ```

3. **Start Backend Server**
   ```bash
   cd backend
   php -S localhost:8000 index.php
   ```

### Frontend Setup

1. **Install Dependencies**
   ```bash
   cd frontend
   npm install
   ```

2. **Configure API Base URL**
   - Update `frontend/.env.development`:
     ```
     VITE_API_BASE=http://localhost:8000
     ```

3. **Start Development Server**
   ```bash
   npm run dev
   ```

## Default Credentials

After database setup, use these credentials to log in:

- **Admin**: admin@college.edu / password123
- **Teacher**: teacher@college.edu / password123
- **Student**: student@college.edu / password123

*(Change passwords after first login)*

## API Endpoints

### Authentication
- `POST /index.php?action=login` - User login
- `POST /index.php?action=logout` - User logout

### Students
- `POST /index.php?action=create_student` - Create new student
- `GET /index.php?action=get_students` - List students

### Courses
- `POST /index.php?action=create_course_offering` - Create course offering
- `GET /index.php?action=get_course_offerings` - List course offerings

### Attendance
- `POST /index.php?action=mark_attendance` - Mark student attendance
- `GET /index.php?action=get_attendance` - Get attendance records

### Marks
- `POST /index.php?action=upload_marks` - Upload student marks
- `GET /index.php?action=get_marks` - Get mark records

## Email Notifications

The system automatically sends emails for:
- **Student Account Creation** - Welcome email with credentials
- **Marks Upload** - Notification when marks are uploaded
- **Assignment Grading** - Notification when assignments are graded
- **Attendance Marking** - Notification of attendance status (present/absent/late/excused)

**Note**: Email functionality requires valid SMTP configuration and an active email account.

## Development

### Running Tests
```bash
cd frontend
npm run lint
```

### Building for Production

**Backend**: No build step needed; deploy PHP files directly.

**Frontend**:
```bash
npm run build
```

Output will be in `frontend/dist/`.

## Troubleshooting

### Database Connection Error
- Verify MySQL is running
- Check credentials in `backend/config.php`
- Ensure database exists and schema is imported

### Frontend API Errors
- Confirm backend server is running on `localhost:8000`
- Check `VITE_API_BASE` in `.env.development`
- Verify CORS headers in backend if needed

### Email Not Sending
- Check SMTP credentials in `backend/config.php`
- Verify Gmail App Password is correct (not regular password)
- Check server logs in `backend/mailer_debug.log`
- Ensure email field is present in student records

## License

Proprietary - College Management System

## Contributors

- Development Team
- Final Year Project (FYP)

## Support

For issues or questions, please contact the development team.
