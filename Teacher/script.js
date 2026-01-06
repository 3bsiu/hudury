
let currentLanguage = 'en';

function toggleLanguage() {
    currentLanguage = currentLanguage === 'en' ? 'ar' : 'en';
    document.documentElement.lang = currentLanguage;
    document.documentElement.dir = currentLanguage === 'ar' ? 'rtl' : 'ltr';
    
    document.querySelectorAll('[data-en][data-ar]').forEach(element => {
        const text = element.getAttribute(`data-${currentLanguage}`);
        if (text) {
            element.textContent = text;
        }
    });

    document.querySelectorAll('[data-placeholder-en][data-placeholder-ar]').forEach(element => {
        const placeholder = element.getAttribute(`data-placeholder-${currentLanguage}`);
        if (placeholder) {
            element.placeholder = placeholder;
        }
    });
}

function toggleRightMenu() {
    const menu = document.getElementById('rightSideMenu');
    const overlay = document.getElementById('menuOverlay');
    
    if (menu && overlay) {
        menu.classList.toggle('active');
        overlay.classList.toggle('active');
        
        if (menu.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }
}

function closeRightMenu() {
    const menu = document.getElementById('rightSideMenu');
    const overlay = document.getElementById('menuOverlay');
    
    if (menu && overlay) {
        menu.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

document.addEventListener('click', function(event) {
    const menu = document.getElementById('rightSideMenu');
    const menuBtn = document.querySelector('.menu-toggle-btn');
    const overlay = document.getElementById('menuOverlay');
    
    if (menu && menu.classList.contains('active')) {
        if (!menu.contains(event.target) && !menuBtn.contains(event.target)) {
            closeRightMenu();
        }
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeRightMenu();
    }
});

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.setAttribute('role', 'alert');
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 1.2rem;">&times;</button>
    `;
    
    const container = document.getElementById('notificationContainer') || document.body;
    container.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

function validateGrade(value, min = 0, max = 100) {
    const num = parseFloat(value);
    if (isNaN(num) || num < min || num > max) {
        return false;
    }
    return true;
}

function formatDate(date) {
    if (!date) return '';
    const d = new Date(date);
    return d.toLocaleDateString(currentLanguage === 'en' ? 'en-US' : 'ar-JO', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatTime(date) {
    if (!date) return '';
    const d = new Date(date);
    return d.toLocaleTimeString(currentLanguage === 'en' ? 'en-US' : 'ar-JO', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

function exportToCSV(data, filename) {
    
    const csv = convertToCSV(data);
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
    showNotification(currentLanguage === 'en' ? 'File exported successfully!' : 'تم تصدير الملف بنجاح!', 'success');
}

function convertToCSV(data) {
    if (!data || data.length === 0) return '';
    
    const headers = Object.keys(data[0]);
    const csvRows = [headers.join(',')];
    
    data.forEach(row => {
        const values = headers.map(header => {
            const value = row[header];
            return typeof value === 'string' ? `"${value.replace(/"/g, '""')}"` : value;
        });
        csvRows.push(values.join(','));
    });
    
    return csvRows.join('\n');
}

function importCSV(file, callback) {
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const text = e.target.result;
        const data = parseCSV(text);
        if (callback) callback(data);
    };
    reader.readAsText(file);
}

function parseCSV(text) {
    const lines = text.split('\n');
    const headers = lines[0].split(',').map(h => h.trim().replace(/"/g, ''));
    const data = [];
    
    for (let i = 1; i < lines.length; i++) {
        if (lines[i].trim()) {
            const values = lines[i].split(',').map(v => v.trim().replace(/"/g, ''));
            const row = {};
            headers.forEach((header, index) => {
                row[header] = values[index] || '';
            });
            data.push(row);
        }
    }
    
    return data;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

document.addEventListener('DOMContentLoaded', function() {
    
    if (!document.getElementById('notificationContainer')) {
        const container = document.createElement('div');
        container.id = 'notificationContainer';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10000; max-width: 400px;';
        document.body.appendChild(container);
    }
});

