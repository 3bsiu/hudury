
const STANDARD_STUDENT_FIELDS = {
    
    id: null,                    
    studentId: null,             
    name: null,                  

    grade: null,                 
    section: null,               

    email: null,                 
    phone: null,                 
    dateOfBirth: null,          
    placeOfBirth: null,         
    nationalId: null,           
    address: null,               

    status: 'active',           
    sponsoringEntity: null,     
    notes: null,                 

    guardianName: null,         
    guardianRole: null,         
    guardianPhone: null,        
    guardianEmail: null,        
    hasGuardianAccount: false,  

    guardianId: null,           
    teacherIds: [],             
    classIds: []                
};

const STANDARD_INSTALLMENT_FIELDS = {
    id: null,                   
    studentId: null,            
    studentName: null,          
    grade: null,                
    section: null,               
    installment: null,          
    amount: null,               
    dueDate: null,              
    status: 'unpaid',           
    paidDate: null,             
    paymentMethod: null,        
    receiptNumber: null,        
    notes: null                 
};

const STANDARD_ATTENDANCE_FIELDS = {
    id: null,                   
    studentId: null,            
    studentName: null,          
    grade: null,                
    section: null,               
    date: null,                 
    status: 'present',         
    timeIn: null,               
    notes: null,                
    excuseSubmitted: false,     
    excuseApproved: false       
};

const STANDARD_MEDICAL_RECORD_FIELDS = {
    studentId: null,            
    allergies: null,            
    bloodType: null,            
    emergencyContact: null,     
    primaryPhysician: null,     
    medications: null,          
    medicalNotes: null,         
    history: []                 
};

const STANDARD_ACADEMIC_STATUS_FIELDS = {
    studentId: null,            
    status: 'active',           
    sponsoringEntity: null,     
    notes: null,                
    enrollmentDate: null,      
    graduationDate: null,       
    academicYear: null          
};

function createStudent(data) {
    return {
        ...STANDARD_STUDENT_FIELDS,
        ...data,
        
        id: data.id || null,
        studentId: data.studentId || data.student_id || null,
        name: data.name || data.studentName || null,
        grade: data.grade || null,
        section: data.section || null,
        status: data.status || 'active'
    };
}

function normalizeStudentData(student) {
    
    return {
        id: student.id,
        studentId: student.studentId || student.student_id,
        name: student.name || student.studentName,
        grade: student.grade,
        section: student.section,
        email: student.email,
        phone: student.phone,
        status: student.status || 'active',
        dateOfBirth: student.dateOfBirth || student.dob,
        placeOfBirth: student.placeOfBirth || student.pob,
        nationalId: student.nationalId || student.national_id,
        address: student.address,
        sponsoringEntity: student.sponsoringEntity || student.sponsoring_entity,
        notes: student.notes,
        guardianName: student.guardianName || student.guardian_name,
        guardianRole: student.guardianRole || student.guardian_role,
        guardianPhone: student.guardianPhone || student.guardian_phone,
        guardianEmail: student.guardianEmail || student.guardian_email,
        hasGuardianAccount: student.hasGuardianAccount || student.has_guardian_account || false
    };
}

const SHARED_MOCK_STUDENTS = [
    {
        id: 1,
        studentId: '12345',
        name: 'Ahmed Ali',
        grade: '5',
        section: 'a',
        email: 'ahmed.ali@school.edu',
        phone: '+962 7 1234 5678',
        status: 'active',
        dateOfBirth: '2010-05-15',
        placeOfBirth: 'Amman',
        nationalId: '1234567890',
        address: 'Amman, Jordan',
        sponsoringEntity: 'Ministry of Education',
        notes: 'Excellent academic performance. Participates actively in class.',
        guardianName: 'Ali Hassan',
        guardianRole: 'father',
        guardianPhone: '+962 7 1234 5678',
        guardianEmail: 'ali.parent@example.com',
        hasGuardianAccount: true
    },
    {
        id: 2,
        studentId: '12346',
        name: 'Sara Mohammad',
        grade: '5',
        section: 'a',
        email: 'sara.mohammad@school.edu',
        phone: '+962 7 4567 8901',
        status: 'active',
        dateOfBirth: '2010-08-20',
        placeOfBirth: 'Irbid',
        nationalId: '0987654321',
        address: 'Irbid, Jordan',
        sponsoringEntity: 'Private Sponsor',
        notes: 'Good progress this semester.',
        guardianName: 'Mohammad Ahmad',
        guardianRole: 'father',
        guardianPhone: '+962 7 4567 8901',
        guardianEmail: 'mohammad.parent@example.com',
        hasGuardianAccount: false
    },
    {
        id: 3,
        studentId: '12347',
        name: 'Omar Hassan',
        grade: '5',
        section: 'b',
        email: 'omar.hassan@school.edu',
        phone: '+962 7 6789 0123',
        status: 'inactive',
        dateOfBirth: '2010-03-10',
        placeOfBirth: 'Zarqa',
        nationalId: '1122334455',
        address: 'Zarqa, Jordan',
        sponsoringEntity: 'Ministry of Education',
        notes: 'Temporarily inactive due to family circumstances.',
        guardianName: 'Hassan Ibrahim',
        guardianRole: 'father',
        guardianPhone: '+962 7 6789 0123',
        guardianEmail: 'hassan.parent@example.com',
        hasGuardianAccount: false
    },
    {
        id: 4,
        studentId: '12348',
        name: 'Layla Ahmad',
        grade: '6',
        section: 'a',
        email: 'layla.ahmad@school.edu',
        phone: '+962 7 2345 6789',
        status: 'active',
        dateOfBirth: '2009-11-25',
        placeOfBirth: 'Amman',
        nationalId: '5566778899',
        address: 'Amman, Jordan',
        sponsoringEntity: 'Private Sponsor',
        notes: 'Outstanding student. Needs advanced materials.',
        guardianName: 'Ahmad Salim',
        guardianRole: 'father',
        guardianPhone: '+962 7 2345 6789',
        guardianEmail: 'ahmad.parent@example.com',
        hasGuardianAccount: true
    },
    {
        id: 5,
        studentId: '12349',
        name: 'Yusuf Ibrahim',
        grade: '6',
        section: 'b',
        email: 'yusuf.ibrahim@school.edu',
        phone: '+962 7 3456 7890',
        status: 'active',
        dateOfBirth: '2009-07-12',
        placeOfBirth: 'Aqaba',
        nationalId: '9988776655',
        address: 'Aqaba, Jordan',
        sponsoringEntity: 'Ministry of Education',
        notes: 'Regular attendance and good performance.',
        guardianName: 'Ibrahim Khalil',
        guardianRole: 'father',
        guardianPhone: '+962 7 3456 7890',
        guardianEmail: 'ibrahim.parent@example.com',
        hasGuardianAccount: false
    }
];

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        STANDARD_STUDENT_FIELDS,
        STANDARD_INSTALLMENT_FIELDS,
        STANDARD_ATTENDANCE_FIELDS,
        STANDARD_MEDICAL_RECORD_FIELDS,
        STANDARD_ACADEMIC_STATUS_FIELDS,
        createStudent,
        normalizeStudentData,
        SHARED_MOCK_STUDENTS
    };
}

