import { Link } from 'react-router-dom';
import Navbar from '../components/Navbar';

const FEATURES = [
  { icon: '📚', title: 'Course Management', desc: 'Create and manage courses, sections, and academic offerings with a comprehensive curriculum builder.', color: 'indigo' },
  { icon: '👨‍🎓', title: 'Student Enrollment', desc: 'Streamlined registration workflows with approval-based enrollment and real-time status tracking.', color: 'blue' },
  { icon: '📝', title: 'Assignments & Quizzes', desc: 'Teachers can create, distribute, and grade assignments with file upload and inline feedback support.', color: 'green' },
  { icon: '📊', title: 'Attendance Tracking', desc: 'Mark and monitor daily attendance with detailed analytics and attendance percentage reports.', color: 'amber' },
  { icon: '🏆', title: 'Results & Grading', desc: 'End-to-end results workflow with teacher submission and admin approval before publishing.', color: 'red' },
  { icon: '🔒', title: 'Role-Based Access', desc: 'Secure multi-role system — Admins, Teachers, and Students each have tailored dashboards and permissions.', color: 'indigo' },
];

const STATS = [
  { num: '500+', label: 'Students Managed' },
  { num: '50+', label: 'Courses Offered' },
  { num: '30+', label: 'Expert Teachers' },
  { num: '99.9%', label: 'System Uptime' },
];

export default function Home() {
  return (
    <>
      <Navbar />

      {/* Hero */}
      <section className="hero">
        <div className="hero-content">
          <div className="hero-badge">🎓 Modern College Management</div>
          <h1>Manage Your <span>Institution</span> Effortlessly</h1>
          <p>
            A comprehensive, all-in-one platform for managing courses, students, teachers,
            attendance, assignments, and results — designed for modern educational institutions.
          </p>
          <div className="hero-actions">
            <Link to="/login" className="hero-btn hero-btn-primary">
              Get Started →
            </Link>
            <Link to="/about" className="hero-btn hero-btn-secondary">
              Learn More
            </Link>
          </div>
        </div>
      </section>

      {/* Features */}
      <section className="features-section">
        <div className="section-header">
          <h2>Everything You Need</h2>
          <p>Powerful features designed to simplify academic management and enhance the learning experience.</p>
        </div>
        <div className="features-grid">
          {FEATURES.map((f) => (
            <div key={f.title} className="feature-card">
              <div className={`feature-icon ${f.color}`}>{f.icon}</div>
              <h3>{f.title}</h3>
              <p>{f.desc}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Stats */}
      <section className="stats-section">
        <div className="stats-grid">
          {STATS.map((s) => (
            <div key={s.label} className="stat-block">
              <div className="stat-num">{s.num}</div>
              <div className="stat-label">{s.label}</div>
            </div>
          ))}
        </div>
      </section>

      {/* Gallery */}
      <section className="gallery-section">
        <div className="section-header">
          <h2>Our Campus</h2>
          <p>Explore the vibrant learning environment that inspires excellence and innovation.</p>
        </div>
        <div className="gallery-grid">
          <div className="gallery-item gallery-item-large">
            <div className="gallery-placeholder" style={{background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'}}></div>
            <div className="gallery-overlay">
              <span>Campus Entrance</span>
            </div>
          </div>
          <div className="gallery-item">
            <div className="gallery-placeholder" style={{background: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)'}}></div>
            <div className="gallery-overlay">
              <span>Library Block</span>
            </div>
          </div>
          <div className="gallery-item">
            <div className="gallery-placeholder" style={{background: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)'}}></div>
            <div className="gallery-overlay">
              <span>Sports Ground</span>
            </div>
          </div>
          <div className="gallery-item">
            <div className="gallery-placeholder" style={{background: 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)'}}></div>
            <div className="gallery-overlay">
              <span>Classroom</span>
            </div>
          </div>
          <div className="gallery-item">
            <div className="gallery-placeholder" style={{background: 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)'}}></div>
            <div className="gallery-overlay">
              <span>Lab Facilities</span>
            </div>
          </div>
          <div className="gallery-item gallery-item-large">
            <div className="gallery-placeholder" style={{background: 'linear-gradient(135deg, #30cfd0 0%, #330867 100%)'}}></div>
            <div className="gallery-overlay">
              <span>Auditorium</span>
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="cta-section">
        <h2>Ready to Transform Your Institution?</h2>
        <p>Join hundreds of educational organizations already using CollegeMS to streamline their operations.</p>
        <Link to="/login" className="hero-btn hero-btn-primary">
          Sign In to Dashboard →
        </Link>
      </section>

      {/* Footer */}
      <footer className="site-footer">
        <p>© {new Date().getFullYear()} College Management System. All rights reserved.</p>
      </footer>
    </>
  );
}
